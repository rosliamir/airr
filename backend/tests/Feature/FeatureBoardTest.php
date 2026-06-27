<?php

namespace Tests\Feature;

use App\Models\FeatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Module readiness board — feature checklist + reviewer status.
class FeatureBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_features_merge_build_facts_and_status(): void
    {
        $this->actingWithPermissions([]); // any authenticated user can view
        FeatureStatus::create(['feature_key' => 'm6.1', 'human_tested' => true, 'ready_for_prod' => true]);

        $res = $this->getJson('/api/modules/M6/features')->assertOk()->assertJsonPath('data.code', 'M6');
        $m61 = collect($res->json('data.features'))->firstWhere('key', 'm6.1');

        $this->assertTrue($m61['developed']);
        $this->assertTrue($m61['ai_tested']);
        $this->assertTrue($m61['human_tested']);   // from stored status
        $this->assertTrue($m61['ready_for_prod']);
    }

    public function test_update_requires_permission(): void
    {
        $this->actingWithPermissions([]); // no settings.manage
        $this->putJson('/api/features/m6.1', ['human_tested' => true])->assertForbidden();
    }

    public function test_admin_can_toggle_status(): void
    {
        $this->actingWithPermissions(['settings.manage']);

        $this->putJson('/api/features/m2.1', ['human_tested' => true, 'ready_for_prod' => true])
            ->assertOk()->assertJsonPath('data.ready_for_prod', true);

        $this->assertDatabaseHas('feature_statuses', ['feature_key' => 'm2.1', 'human_tested' => true]);
    }

    public function test_summary_counts_per_module(): void
    {
        $this->actingWithPermissions([]);
        FeatureStatus::create(['feature_key' => 'm2.1', 'ready_for_prod' => true]);

        $res = $this->getJson('/api/modules/features/summary')->assertOk();
        $this->assertGreaterThan(0, $res->json('data.M2.developed'));
        $this->assertSame(1, $res->json('data.M2.ready_for_prod'));
    }
}
