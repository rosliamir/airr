<?php

namespace Tests;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a normal (non-admin) user holding the given permission keys via a
     * role, and authenticate as them. Returns the user.
     *
     * @param  string[]  $permissions
     */
    protected function actingWithPermissions(array $permissions = []): User
    {
        $user = User::factory()->create(['user_type' => User::TYPE_USER]);

        if ($permissions) {
            $role = Role::create(['slug' => 'test-role', 'name' => 'Test Role', 'clearance' => 1]);
            foreach ($permissions as $key) {
                $role->permissions()->attach(Permission::firstOrCreate(['key' => $key])->id);
            }
            $user->roles()->attach($role->id);
        }

        Sanctum::actingAs($user);

        return $user;
    }
}
