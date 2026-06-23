<?php

namespace Database\Seeders;

use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions from the registry (FR-M1.2).
        $permIds = [];
        foreach (Permission::all() as $key) {
            $permIds[$key] = PermissionModel::firstOrCreate(['key' => $key])->id;
        }

        // Roles with clearance levels (clearance drives zero-trust RAG / RLS later).
        $superadmin = Role::updateOrCreate(['slug' => 'superadmin'], [
            'name' => 'Super Administrator', 'description' => 'Platform owner — unrestricted', 'clearance' => 1000, 'is_system' => true,
        ]);
        $admin = Role::updateOrCreate(['slug' => 'admin'], [
            'name' => 'System Administrator', 'description' => 'Full access', 'clearance' => 100, 'is_system' => true,
        ]);
        $ba = Role::updateOrCreate(['slug' => 'ba'], [
            'name' => 'Business Analyst', 'description' => 'Authors reports', 'clearance' => 50, 'is_system' => true,
        ]);
        $viewer = Role::updateOrCreate(['slug' => 'viewer'], [
            'name' => 'Viewer', 'description' => 'Runs and views reports', 'clearance' => 10, 'is_system' => true,
        ]);

        // Superadmin and admin both get every permission (superadmin outranks via clearance).
        $superadmin->permissions()->sync(array_values($permIds));
        $admin->permissions()->sync(array_values($permIds));

        // BA: author + run + KB + data sources (no user/role/settings admin).
        $ba->permissions()->sync(collect($permIds)->only([
            Permission::REPORTS_VIEW, Permission::REPORTS_CREATE, Permission::REPORTS_EDIT,
            Permission::REPORTS_RUN, Permission::REPORTS_EXPORT, Permission::REPORTS_PUBLISH,
            Permission::DATASOURCES_VIEW, Permission::DATASOURCES_MANAGE,
            Permission::KB_VIEW, Permission::KB_MANAGE,
        ])->values()->all());

        // Viewer: read/run/export only.
        $viewer->permissions()->sync(collect($permIds)->only([
            Permission::REPORTS_VIEW, Permission::REPORTS_RUN, Permission::REPORTS_EXPORT,
        ])->values()->all());

        // Default superadmin user (user_type = system_admin classification).
        $superUser = User::updateOrCreate(
            ['email' => env('AIRR_SUPERADMIN_EMAIL', 'superadmin@airr.technology')],
            [
                'name'      => 'AIRR Super Admin',
                'password'  => Hash::make(env('AIRR_SUPERADMIN_PASSWORD', 'airr12345')),
                'status'    => User::STATUS_ACTIVE,
                'user_type' => User::TYPE_SYSTEM_ADMIN,
            ],
        );
        $superUser->roles()->sync([$superadmin->id]);

        // Default admin user.
        $user = User::updateOrCreate(
            ['email' => env('AIRR_ADMIN_EMAIL', 'admin@airr.technology')],
            [
                'name'      => 'AIRR Admin',
                'password'  => Hash::make(env('AIRR_ADMIN_PASSWORD', 'airr12345')),
                'status'    => User::STATUS_ACTIVE,
                'user_type' => User::TYPE_ADMIN,
            ],
        );
        $user->roles()->sync([$admin->id]);
    }
}
