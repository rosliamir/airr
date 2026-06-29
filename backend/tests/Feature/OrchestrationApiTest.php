<?php

namespace Tests\Feature;

use App\Jobs\RunOrchestrationPipeline;
use App\Models\OrchestrationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// M4 — Orchestration API feature tests.
class OrchestrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // ── store ───────────────────────────────────────────────────────────────

    public function test_submit_creates_run_and_dispatches_job(): void
    {
        $this->actingWithPermissions(['reports.create']);

        $this->postJson('/api/orchestration', ['prompt' => 'Show me monthly user signups for Q1'])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'status', 'prompt']])
            ->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseCount('orchestration_runs', 1);
        $this->assertDatabaseHas('orchestration_runs', ['status' => 'queued']);
    }

    public function test_submit_rejects_short_prompt(): void
    {
        $this->actingWithPermissions(['reports.create']);

        $this->postJson('/api/orchestration', ['prompt' => 'too short'])
            ->assertStatus(422);
    }

    public function test_submit_requires_auth(): void
    {
        $this->postJson('/api/orchestration', ['prompt' => 'Show me monthly user signups'])
            ->assertStatus(401);
    }

    // ── index ───────────────────────────────────────────────────────────────

    public function test_index_returns_only_users_own_runs(): void
    {
        $user  = $this->actingWithPermissions(['reports.view']);
        $other = User::factory()->create();

        OrchestrationRun::factory()->create(['created_by' => $user->id]);
        OrchestrationRun::factory()->create(['created_by' => $other->id]);

        $res = $this->getJson('/api/orchestration')->assertOk();
        $this->assertCount(1, $res->json('data.data'));
    }

    // ── show ────────────────────────────────────────────────────────────────

    public function test_show_returns_run(): void
    {
        $user = $this->actingWithPermissions(['reports.view']);
        $run  = OrchestrationRun::factory()->create(['created_by' => $user->id, 'status' => 'complete']);

        $this->getJson("/api/orchestration/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $run->id);
    }

    public function test_show_403_for_another_users_run(): void
    {
        $this->actingWithPermissions(['reports.view']);
        $other = User::factory()->create();
        $run   = OrchestrationRun::factory()->create(['created_by' => $other->id]);

        $this->getJson("/api/orchestration/{$run->id}")->assertStatus(403);
    }

    // ── compliance ──────────────────────────────────────────────────────────

    public function test_compliance_409_when_run_not_complete(): void
    {
        $user = $this->actingWithPermissions(['reports.edit']);
        $run  = OrchestrationRun::factory()->create(['created_by' => $user->id, 'status' => 'running']);

        $this->postJson("/api/orchestration/{$run->id}/compliance")->assertStatus(409);
    }

    // ── promote ─────────────────────────────────────────────────────────────

    public function test_promote_rejected_when_compliance_failed(): void
    {
        $user = $this->actingWithPermissions(['reports.create']);
        $run  = OrchestrationRun::factory()->create([
            'created_by'       => $user->id,
            'status'           => 'complete',
            'compliance_passed'=> false,
            'output_html'      => '<h2>Report</h2>',
        ]);

        $this->postJson("/api/orchestration/{$run->id}/promote", ['name' => 'My Report'])
            ->assertStatus(422);
    }

    public function test_promote_rejected_when_run_not_complete(): void
    {
        $user = $this->actingWithPermissions(['reports.create']);
        $run  = OrchestrationRun::factory()->create([
            'created_by' => $user->id,
            'status'     => 'failed',
        ]);

        $this->postJson("/api/orchestration/{$run->id}/promote", ['name' => 'My Report'])
            ->assertStatus(409);
    }
}
