<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Role;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// FR-M14.2 / M1.2: role catalogue + CRUD. Gated by `roles.manage`.
// NOTE: permission-to-role assignment is deferred until Authoring/Data Source
// permissions exist (per roadmap) — this CRUD covers name/description/clearance.
class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->orderByDesc('clearance')
            ->get()
            ->map(fn (Role $r) => $this->row($r));

        return $this->sendOk($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRole($request);

        $role = Role::create([
            'slug'        => $data['slug'] ?? Str::slug($data['name']),
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'clearance'   => $data['clearance'],
            'is_system'   => false,
        ]);

        $this->audit->log('role.created', Role::class, $role->id, null, $data);

        return $this->sendCreated($this->row($role->loadCount(['permissions', 'users'])));
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $this->validateRole($request, $role);

        // System roles keep their slug (referenced in code); name/desc/clearance editable.
        $role->fill([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'clearance'   => $data['clearance'],
        ]);
        if (! $role->is_system && ! empty($data['slug'])) {
            $role->slug = $data['slug'];
        }
        $role->save();

        $this->audit->log('role.updated', Role::class, $role->id, null, $data);

        return $this->sendOk($this->row($role->loadCount(['permissions', 'users'])));
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return $this->sendError(422, 'INVALID_OPERATION', 'System roles cannot be deleted.');
        }
        if ($role->users()->exists()) {
            return $this->sendError(422, 'ROLE_IN_USE', 'Unassign this role from all users before deleting it.');
        }

        $role->permissions()->detach();
        $role->delete();
        $this->audit->log('role.deleted', Role::class, $role->id);

        return $this->sendNoContent();
    }

    private function validateRole(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name'        => 'required|string|max:120',
            'slug'        => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('roles', 'slug')->ignore($role?->id)],
            'description' => 'nullable|string|max:255',
            'clearance'   => 'required|integer|min:0|max:1000',
        ]);
    }

    private function row(Role $r): array
    {
        return [
            'id'                => $r->id,
            'slug'              => $r->slug,
            'name'              => $r->name,
            'description'       => $r->description,
            'clearance'         => $r->clearance,
            'is_system'         => $r->is_system,
            'permissions_count' => $r->permissions_count ?? 0,
            'users_count'       => $r->users_count ?? 0,
        ];
    }
}
