<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// M14 — administration CRUD + RBAC gating (projects, users) and owner scoping.
class AdminCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_delete_project(): void
    {
        $this->actingWithPermissions(['projects.manage', 'projects.view']);

        $id = $this->postJson('/api/projects', ['code' => 'PRJ-9', 'name' => 'Demo'])
            ->assertCreated()->json('data.id');

        $this->deleteJson("/api/projects/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('projects', ['id' => $id]);
    }

    public function test_project_listing_is_owner_scoped_for_non_admin(): void // FR-M14.3
    {
        $other = User::factory()->create(['user_type' => User::TYPE_USER_LEVEL_1]);
        Project::create(['code' => 'OTHER', 'name' => 'Not mine', 'status' => 'active', 'created_by' => $other->id]);

        $viewer = $this->actingWithPermissions(['projects.view', 'projects.manage']);
        Project::create(['code' => 'MINE', 'name' => 'Mine', 'status' => 'active', 'created_by' => $viewer->id]);

        $res = $this->getJson('/api/projects')->assertOk();
        $codes = collect($res->json('data'))->pluck('code');

        $this->assertTrue($codes->contains('MINE'));
        $this->assertFalse($codes->contains('OTHER'));
    }

    public function test_projects_require_view_permission(): void
    {
        $this->actingWithPermissions([]);
        $this->getJson('/api/projects')->assertForbidden();
    }

    public function test_admin_can_create_user(): void
    {
        $this->actingWithPermissions(['users.manage']);

        $this->postJson('/api/users', [
            'name' => 'Staff', 'email' => 'staff@example.com',
            'password' => 'Password123!', 'user_type' => User::TYPE_USER_LEVEL_1,
        ])->assertCreated()->assertJsonPath('data.email', 'staff@example.com');

        $this->assertDatabaseHas('users', ['email' => 'staff@example.com']);
    }

    public function test_user_management_requires_permission(): void
    {
        $this->actingWithPermissions([]);
        $this->getJson('/api/users')->assertForbidden();
    }
}
