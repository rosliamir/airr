<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Dashboard Authoring — widget-based dashboard CRUD + render engine.
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_list_dashboard(): void
    {
        $this->actingWithPermissions(['dashboards.create', 'dashboards.view']);

        $this->postJson('/api/dashboards', [
            'name' => 'Ops', 'definition' => ['widgets' => []],
        ])->assertCreated()->assertJsonPath('data.name', 'Ops');

        $this->getJson('/api/dashboards')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_dashboard_without_grant_is_private_to_creator(): void
    {
        $owner = User::factory()->create(['user_type' => User::TYPE_USER_LEVEL_1]);
        $dashboard = Dashboard::create(['name' => 'Secret', 'created_by' => $owner->id]);

        $this->actingWithPermissions(['dashboards.view', 'dashboards.run']);
        $this->getJson('/api/dashboards')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/dashboards/{$dashboard->id}")->assertForbidden();
    }

    public function test_role_grant_gives_view_access(): void
    {
        $owner = User::factory()->create(['user_type' => User::TYPE_USER_LEVEL_1]);
        $dashboard = Dashboard::create(['name' => 'Shared', 'created_by' => $owner->id]);

        $viewer = $this->actingWithPermissions(['dashboards.view']);
        $dashboard->syncPermissions([['role_id' => $viewer->roles()->first()->id, 'view' => true, 'run' => true]]);

        $this->getJson('/api/dashboards')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/dashboards/{$dashboard->id}")->assertOk();
    }

    public function test_run_requires_can_run_grant(): void
    {
        $owner = User::factory()->create(['user_type' => User::TYPE_USER_LEVEL_1]);
        $dashboard = Dashboard::create(['name' => 'NoRun', 'created_by' => $owner->id]);

        $viewer = $this->actingWithPermissions(['dashboards.view', 'dashboards.run']);
        $dashboard->syncPermissions([['role_id' => $viewer->roles()->first()->id, 'view' => true, 'run' => false]]);

        $this->postJson("/api/dashboards/{$dashboard->id}/run")->assertForbidden();
    }

    public function test_creator_keeps_full_access(): void
    {
        $creator = $this->actingWithPermissions(['dashboards.view', 'dashboards.edit']);
        $dashboard = Dashboard::create(['name' => 'Mine', 'created_by' => $creator->id]);

        $this->getJson("/api/dashboards/{$dashboard->id}")->assertOk();
        $this->putJson("/api/dashboards/{$dashboard->id}", ['name' => 'Mine 2', 'definition' => ['widgets' => []]])->assertOk();
    }

    public function test_run_renders_text_widget_content(): void
    {
        $creator = $this->actingWithPermissions(['dashboards.view', 'dashboards.create', 'dashboards.run']);
        $dashboard = Dashboard::create([
            'name' => 'Text', 'created_by' => $creator->id,
            'definition' => ['widgets' => [
                ['id' => 'w1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2, 'config' => ['title' => 'Hello', 'content' => 'World']],
            ]],
        ]);

        $html = $this->postJson("/api/dashboards/{$dashboard->id}/run")->assertOk()->json('data.html');
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('World', $html);
    }

    public function test_run_with_unknown_report_id_renders_placeholder_not_throws(): void
    {
        $creator = $this->actingWithPermissions(['dashboards.view', 'dashboards.create', 'dashboards.run']);
        $dashboard = Dashboard::create([
            'name' => 'BadReport', 'created_by' => $creator->id,
            'definition' => ['widgets' => [
                ['id' => 'w1', 'type' => 'report', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4, 'config' => ['report_id' => 999999]],
            ]],
        ]);

        $html = $this->postJson("/api/dashboards/{$dashboard->id}/run")->assertOk()->json('data.html');
        $this->assertStringContainsString('Report not found', $html);
    }
}
