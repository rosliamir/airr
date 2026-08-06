<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// M6 — SavedView reset (discard AI customization) + restore (step back to a
// pre-prompt snapshot recorded in prompt_history[*].before).
class SavedViewTest extends TestCase
{
    use RefreshDatabase;

    private function ownedView(array $definition): SavedView
    {
        $user = $this->actingWithPermissions(['reports.run']);
        $report = Report::create([
            'name' => 'Source', 'type' => 'table',
            'definition' => ['type' => 'table', 'columns' => [['field' => 'region', 'label' => 'Region']]],
            'created_by' => $user->id,
        ]);

        return SavedView::create([
            'report_id' => $report->id, 'user_id' => $user->id,
            'name' => 'Mine', 'definition' => $definition,
        ]);
    }

    public function test_reset_discards_customization_but_keeps_prompt_history(): void
    {
        $view = $this->ownedView([
            'type' => 'table',
            'columns' => [['field' => 'region', 'label' => 'Custom Label']],
            'prompt_history' => [['text' => 'rename column', 'at' => now()->toIso8601String()]],
        ]);

        $res = $this->postJson("/api/saved-views/{$view->id}/reset")->assertOk();

        $this->assertSame(['field' => 'region', 'label' => 'Region'], $res->json('data.definition.columns.0'));
        $this->assertCount(1, $res->json('data.definition.prompt_history'));
        $this->assertNull($res->json('data.prompt'));
    }

    public function test_restore_reverts_to_before_snapshot(): void
    {
        $before = ['type' => 'table', 'columns' => [['field' => 'region', 'label' => 'Region']]];
        $view = $this->ownedView([
            'type' => 'table',
            'columns' => [['field' => 'region', 'label' => 'Renamed']],
            'prompt_history' => [['text' => 'rename column', 'at' => now()->toIso8601String(), 'before' => $before]],
        ]);

        $res = $this->postJson("/api/saved-views/{$view->id}/restore", ['index' => 0])->assertOk();

        $this->assertSame('Region', $res->json('data.definition.columns.0.label'));
        $this->assertCount(1, $res->json('data.definition.prompt_history')); // log preserved
    }

    public function test_restore_404_when_entry_has_no_before_snapshot(): void
    {
        $view = $this->ownedView([
            'type' => 'table',
            'columns' => [],
            'prompt_history' => [['text' => 'old style entry', 'at' => now()->toIso8601String()]],
        ]);

        $this->postJson("/api/saved-views/{$view->id}/restore", ['index' => 0])
            ->assertStatus(404)->assertJsonPath('error.code', 'HISTORY_ENTRY_NOT_FOUND');
    }

    public function test_reset_and_restore_require_ownership(): void
    {
        $view = $this->ownedView(['type' => 'table', 'columns' => []]);

        // A different, non-owning user (bypass actingWithPermissions to avoid
        // colliding with the fixed 'test-role' slug ownedView() already created).
        Sanctum::actingAs(User::factory()->create(['user_type' => User::TYPE_USER_LEVEL_1]));
        $this->postJson("/api/saved-views/{$view->id}/reset")->assertForbidden();
        $this->postJson("/api/saved-views/{$view->id}/restore", ['index' => 0])->assertForbidden();
    }
}
