<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// M6 — reusable report templates: sections, groups, history, soft-delete.
class TemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persists_all_sections_and_groups(): void // FR-M6.4
    {
        $this->actingWithPermissions(['reports.create']);

        $res = $this->postJson('/api/templates', [
            'name'             => 'Invoice Template',
            'header'           => 'Report header',
            'body'             => 'Body content',
            'footer'           => 'Report footer',
            'page_header'      => 'Page header',
            'page_footer'      => 'Page footer',
            'parameter_screen' => 'Pick a date range',
            'groups'           => [
                ['level' => 1, 'header' => 'Group 1 header', 'footer' => 'Group 1 footer'],
                ['level' => 2, 'header' => 'Group 2 header', 'footer' => 'Group 2 footer'],
            ],
        ])->assertCreated();

        $id = $res->json('data.id');
        $show = $this->getJson("/api/templates/{$id}")->assertOk();

        $this->assertSame('Page header', $show->json('data.page_header'));
        $this->assertCount(2, $show->json('data.groups'));
        $this->assertSame('Group 2 footer', $show->json('data.groups.1.footer'));
    }

    public function test_write_requires_permission(): void
    {
        $this->actingWithPermissions([]);

        $this->postJson('/api/templates', ['name' => 'X'])->assertForbidden();
    }

    public function test_index_shows_global_and_project_scoped(): void // FR-M6.4
    {
        $user = $this->actingWithPermissions(['reports.create']);
        $project = Project::create(['code' => 'PRJ-T', 'name' => 'Test', 'status' => 'active', 'created_by' => $user->id]);

        Template::create(['name' => 'Global', 'created_by' => $user->id]);
        Template::create(['name' => 'Scoped', 'project_id' => $project->id, 'created_by' => $user->id]);

        $res = $this->getJson("/api/templates?project_id={$project->id}")->assertOk();
        $names = collect($res->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Global'));
        $this->assertTrue($names->contains('Scoped'));
    }

    public function test_update_writes_history_snapshot(): void // FR-M6.5
    {
        $this->actingWithPermissions(['reports.create']);

        $id = $this->postJson('/api/templates', ['name' => 'T1', 'body' => 'v1'])->json('data.id');
        $this->putJson("/api/templates/{$id}", ['name' => 'T1', 'body' => 'v2'])->assertOk();

        $history = $this->getJson("/api/templates/{$id}/history")->assertOk()->json('data');

        $this->assertCount(2, $history); // created + updated
        $this->assertSame('updated', $history[0]['action']);
        $this->assertSame('v2', $history[0]['snapshot']['body']);
    }

    public function test_soft_delete_and_restore(): void // FR-M6.4
    {
        $this->actingWithPermissions(['reports.create']);

        $id = $this->postJson('/api/templates', ['name' => 'ToDelete'])->json('data.id');
        $this->deleteJson("/api/templates/{$id}")->assertNoContent();

        $this->assertSoftDeleted('templates', ['id' => $id]);

        $this->postJson("/api/templates/{$id}/restore")->assertOk();
        $this->assertDatabaseHas('templates', ['id' => $id, 'deleted_at' => null]);
    }
}
