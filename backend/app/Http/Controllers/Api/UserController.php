<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

// FR-M14.4 / M1.2: user administration — approve pending sign-ups, assign
// roles, suspend/reactivate. Gated by `users.manage`.
class UserController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    // Paginated user list with roles + status; optional ?status= and ?q= filters.
    public function index(Request $request): JsonResponse
    {
        $page   = (int) $request->input('page', 1);
        $limit  = (int) $request->input('limit', 20);
        $status = $request->input('status');
        $q      = $request->input('q');

        $query = User::query()->with(['roles:id,slug,name', 'group:id,name']);

        // Owner+group visibility scoping (FR-M14.3) — admins see everything.
        if (! $request->user()->seesEverything()) {
            $query->visibleTo($request->user());
        }

        if (in_array($status, [User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_SUSPENDED], true)) {
            $query->where('status', $status);
        }
        if ($q) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"));
        }

        $total = $query->count();
        $rows = $query->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get()
            ->map(fn (User $u) => $this->row($u));

        return $this->sendOk($rows, [
            'page'       => $page,
            'limit'      => $limit,
            'total'      => $total,
            'totalPages' => (int) ceil($total / max($limit, 1)),
        ]);
    }

    // Create a user directly (admin-provisioned). Defaults to active.
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:120',
            'email'         => 'required|email|max:190|unique:users,email',
            'password'      => ['required', 'string', Password::min(8)],
            'user_type'     => ['required', Rule::in(User::USER_TYPES)],
            'status'        => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_PENDING, User::STATUS_SUSPENDED])],
            'user_group_id' => 'nullable|integer|exists:user_groups,id',
            'roles'         => 'array',
            'roles.*'       => 'integer|exists:roles,id',
        ]);

        $user = User::create([
            'name'          => $data['name'],
            'email'         => $data['email'],
            'password'      => $data['password'],
            'user_type'     => $data['user_type'],
            'status'        => $data['status'] ?? User::STATUS_ACTIVE,
            'user_group_id' => $data['user_group_id'] ?? null,
            'created_by'    => $request->user()->id, // ownership for visibility scoping
            'auth_provider' => 'local',
        ]);
        $user->roles()->sync($data['roles'] ?? []);

        $this->audit->log('user.created', User::class, $user->id, null, ['email' => $user->email, 'user_type' => $user->user_type]);

        return $this->sendCreated($this->row($user->fresh(['roles', 'group'])));
    }

    // Edit a user (profile, type, group, status, roles; password optional).
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'sometimes|string|max:120',
            'email'         => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password'      => ['nullable', 'string', Password::min(8)],
            'user_type'     => ['sometimes', Rule::in(User::USER_TYPES)],
            'status'        => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_PENDING, User::STATUS_SUSPENDED])],
            'user_group_id' => 'nullable|integer|exists:user_groups,id',
            'roles'         => 'sometimes|array',
            'roles.*'       => 'integer|exists:roles,id',
        ]);

        // Guard: don't let an admin suspend/demote themselves out of access.
        if ($request->user()->id === $user->id && isset($data['status']) && $data['status'] !== User::STATUS_ACTIVE) {
            return $this->sendError(422, 'INVALID_OPERATION', 'You cannot deactivate your own account.');
        }

        $user->fill(collect($data)->except(['password', 'roles'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        if (array_key_exists('roles', $data)) {
            $user->roles()->sync($data['roles']);
        }

        $this->audit->log('user.updated', User::class, $user->id, null, collect($data)->except('password')->all());

        return $this->sendOk($this->row($user->fresh(['roles', 'group'])));
    }

    // Delete a user. Cannot delete yourself.
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return $this->sendError(422, 'INVALID_OPERATION', 'You cannot delete your own account.');
        }

        $user->roles()->detach();
        $user->delete();
        $this->audit->log('user.deleted', User::class, $user->id);

        return $this->sendNoContent();
    }

    // Approve a pending account: activate and (optionally) assign roles.
    public function approve(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles'   => 'array',
            'roles.*' => 'integer|exists:roles,id',
        ]);

        $user->update(['status' => User::STATUS_ACTIVE]);
        if (! empty($data['roles'])) {
            $user->roles()->sync($data['roles']);
        }

        $this->audit->log('user.approved', User::class, $user->id, null, ['status' => 'active', 'roles' => $data['roles'] ?? []]);

        return $this->sendOk($this->row($user->fresh('roles')));
    }

    // Replace the user's role assignment.
    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles'   => 'present|array',
            'roles.*' => 'integer|exists:roles,id',
        ]);

        $old = $user->roles()->pluck('roles.id')->all();
        $user->roles()->sync($data['roles']);

        $this->audit->log('user.roles_updated', User::class, $user->id, ['roles' => $old], ['roles' => $data['roles']]);

        return $this->sendOk($this->row($user->fresh('roles')));
    }

    // Suspend (block login). Cannot suspend yourself.
    public function suspend(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return $this->sendError(422, 'INVALID_OPERATION', 'You cannot suspend your own account.');
        }

        $user->update(['status' => User::STATUS_SUSPENDED]);
        $this->audit->log('user.suspended', User::class, $user->id);

        return $this->sendOk($this->row($user->fresh('roles')));
    }

    // Reactivate a suspended account.
    public function reactivate(Request $request, User $user): JsonResponse
    {
        $user->update(['status' => User::STATUS_ACTIVE]);
        $this->audit->log('user.reactivated', User::class, $user->id);

        return $this->sendOk($this->row($user->fresh('roles')));
    }

    // Upload/replace a user's avatar (stored on the local public disk — sovereign).
    public function uploadAvatar(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Remove a previously uploaded file (skip external Google URLs).
        if ($user->avatar_url && str_starts_with($user->avatar_url, config('app.url') . '/storage/')) {
            $old = str_replace(config('app.url') . '/storage/', '', $user->avatar_url);
            Storage::disk('public')->delete($old);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_url' => Storage::disk('public')->url($path)]);
        $this->audit->log('user.avatar_updated', User::class, $user->id);

        return $this->sendOk($this->row($user->fresh(['roles', 'group'])));
    }

    private function row(User $u): array
    {
        return [
            'id'            => $u->id,
            'name'          => $u->name,
            'email'         => $u->email,
            'status'        => $u->status,
            'user_type'     => $u->user_type,
            'group'         => $u->group ? ['id' => $u->group->id, 'name' => $u->group->name] : null,
            'auth_provider' => $u->auth_provider,
            'avatar_url'    => $u->avatar_url,
            'roles'         => $u->roles->map(fn (Role $r) => ['id' => $r->id, 'slug' => $r->slug, 'name' => $r->name]),
            'clearance'     => $u->clearance(),
            'created_at'    => $u->created_at,
        ];
    }
}
