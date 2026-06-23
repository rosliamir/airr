<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\UserGroup;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// FR-M14.4: user group CRUD (org/department scoping). Gated by `users.manage`.
class UserGroupController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    public function index(): JsonResponse
    {
        $groups = UserGroup::query()
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (UserGroup $g) => $this->row($g));

        return $this->sendOk($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateGroup($request);
        $group = UserGroup::create($data);
        $this->audit->log('user_group.created', UserGroup::class, $group->id, null, $data);

        return $this->sendCreated($this->row($group->loadCount('users')));
    }

    public function update(Request $request, UserGroup $userGroup): JsonResponse
    {
        $data = $this->validateGroup($request, $userGroup);
        $userGroup->update($data);
        $this->audit->log('user_group.updated', UserGroup::class, $userGroup->id, null, $data);

        return $this->sendOk($this->row($userGroup->loadCount('users')));
    }

    public function destroy(UserGroup $userGroup): JsonResponse
    {
        // Members are detached automatically (FK nullOnDelete).
        $userGroup->delete();
        $this->audit->log('user_group.deleted', UserGroup::class, $userGroup->id);

        return $this->sendNoContent();
    }

    private function validateGroup(Request $request, ?UserGroup $group = null): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:120', Rule::unique('user_groups', 'name')->ignore($group?->id)],
            'description' => 'nullable|string|max:255',
        ]);
    }

    private function row(UserGroup $g): array
    {
        return [
            'id'          => $g->id,
            'name'        => $g->name,
            'description' => $g->description,
            'users_count' => $g->users_count ?? 0,
        ];
    }
}
