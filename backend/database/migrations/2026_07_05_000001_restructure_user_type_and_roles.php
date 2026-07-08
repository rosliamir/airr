<?php

use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Support\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 4-tier user_type + role-band restructure (per RBAC diagram, 2026-07-05):
//   Super Admin (all projects) > System Admin (own-created only) >
//   User Level 1 "Manager band" > User Level 2 "Executive band" (both member-based).
return new class extends Migration
{
    public function up(): void
    {
        // New user_type lookup values.
        $rows = [
            ['user_type', 'super_admin', 'Super Admin', 0, true],
            ['user_type', 'system_admin', 'System Admin', 1, true],
            ['user_type', 'user_level_1', 'User Level 1', 2, true],
            ['user_type', 'user_level_2', 'User Level 2', 3, true],
        ];
        foreach ($rows as [$category, $value, $label, $sort, $system]) {
            DB::table('lookups')->updateOrInsert(
                ['category' => $category, 'value' => $value],
                ['label' => $label, 'sort' => $sort, 'is_active' => true, 'is_system' => $system, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        // Stray, un-seeded test value — confirmed unused by any account.
        DB::table('lookups')->where(['category' => 'user_type', 'value' => 'User2'])->delete();

        // Remap existing accounts. Single CASE update — each row's OLD value is read
        // before any row is written, so 'system_admin' -> 'super_admin' and
        // 'admin' -> 'system_admin' cannot collide with each other.
        DB::statement("
            UPDATE users SET user_type = CASE user_type
                WHEN 'system_admin' THEN 'super_admin'
                WHEN 'admin' THEN 'system_admin'
                WHEN 'user' THEN 'user_level_1'
                ELSE user_type
            END
        ");

        // Permission ids by key (registry already seeded by RbacSeeder).
        $permIds = collect(Permission::all())->mapWithKeys(
            fn ($key) => [$key => PermissionModel::firstOrCreate(['key' => $key])->id],
        );

        $roles = [
            'system-admin' => [
                'name' => 'System Admin', 'clearance' => 800,
                'permissions' => [Permission::PROJECTS_MANAGE, Permission::PROJECTS_VIEW, Permission::USERS_MANAGE, Permission::ROLES_MANAGE, Permission::AUDIT_READ, Permission::SETTINGS_MANAGE],
            ],
            'project-manager' => [
                'name' => 'Project Manager', 'clearance' => 560,
                'permissions' => [Permission::REPORTS_VIEW, Permission::REPORTS_CREATE, Permission::REPORTS_EDIT, Permission::REPORTS_RUN, Permission::REPORTS_EXPORT, Permission::REPORTS_PUBLISH, Permission::DATASOURCES_VIEW, Permission::DATASOURCES_MANAGE, Permission::KB_VIEW, Permission::KB_MANAGE, Permission::PROJECTS_VIEW],
            ],
            'project-director' => [
                'name' => 'Project Director', 'clearance' => 540,
                'permissions' => [Permission::REPORTS_VIEW, Permission::REPORTS_CREATE, Permission::REPORTS_EDIT, Permission::REPORTS_RUN, Permission::REPORTS_EXPORT, Permission::REPORTS_PUBLISH, Permission::DATASOURCES_VIEW, Permission::DATASOURCES_MANAGE, Permission::KB_VIEW, Permission::KB_MANAGE, Permission::PROJECTS_VIEW],
            ],
            'project-leader' => [
                'name' => 'Project Leader', 'clearance' => 520,
                'permissions' => [Permission::REPORTS_VIEW, Permission::REPORTS_CREATE, Permission::REPORTS_EDIT, Permission::REPORTS_RUN, Permission::REPORTS_EXPORT, Permission::REPORTS_PUBLISH, Permission::DATASOURCES_VIEW, Permission::DATASOURCES_MANAGE, Permission::KB_VIEW, Permission::KB_MANAGE, Permission::PROJECTS_VIEW],
            ],
            'sa' => [
                'name' => 'SA', 'clearance' => 240,
                'permissions' => [Permission::REPORTS_VIEW, Permission::REPORTS_CREATE, Permission::REPORTS_EDIT, Permission::REPORTS_RUN, Permission::DATASOURCES_VIEW, Permission::KB_VIEW, Permission::KB_MANAGE],
            ],
            'developer' => [
                'name' => 'Developer', 'clearance' => 200,
                'permissions' => [Permission::REPORTS_VIEW, Permission::REPORTS_RUN, Permission::DATASOURCES_VIEW, Permission::DATASOURCES_MANAGE, Permission::KB_VIEW],
            ],
            'implementer' => [
                'name' => 'Implementer', 'clearance' => 180,
                'permissions' => [Permission::REPORTS_VIEW, Permission::REPORTS_RUN, Permission::DATASOURCES_VIEW],
            ],
        ];

        foreach ($roles as $slug => $def) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $def['name'], 'description' => $def['name'] . ' — Executive/Manager band', 'clearance' => $def['clearance'], 'is_system' => true],
            );
            $role->permissions()->sync($permIds->only($def['permissions'])->values()->all());
        }
    }

    public function down(): void
    {
        DB::statement("
            UPDATE users SET user_type = CASE user_type
                WHEN 'super_admin' THEN 'system_admin'
                WHEN 'system_admin' THEN 'admin'
                WHEN 'user_level_1' THEN 'user'
                WHEN 'user_level_2' THEN 'user'
                ELSE user_type
            END
        ");

        DB::table('lookups')->where('category', 'user_type')
            ->whereIn('value', ['super_admin', 'system_admin', 'user_level_1', 'user_level_2'])
            ->delete();

        Role::whereIn('slug', ['system-admin', 'project-manager', 'project-director', 'project-leader', 'sa', 'developer', 'implementer'])->delete();
    }
};
