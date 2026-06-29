<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// FR-M14.6 / FR-M14.5 — AI config API + per-project override persistence.
class AiConfigApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_defaults_and_tasks(): void
    {
        $this->actingWithPermissions(['settings.manage']);

        $this->getJson('/api/ai/config')
            ->assertOk()
            ->assertJsonPath('data.provider', 'ollama')
            ->assertJsonPath('data.tasks', ['embedding', 'generation', 'reasoning', 'audit', 'retrieval', 'compliance']);
    }

    public function test_update_defaults_persists_to_settings(): void
    {
        $this->actingWithPermissions(['settings.manage']);

        $this->putJson('/api/ai/config', [
            'provider' => 'ollama',
            'models' => ['reasoning' => 'llama3.1:70b', 'bogus' => 'ignored'],
        ])->assertOk()->assertJsonPath('data.defaults.models.reasoning', 'llama3.1:70b');

        $stored = Setting::get('ai');
        $this->assertSame('llama3.1:70b', $stored['models']['reasoning']);
        $this->assertArrayNotHasKey('bogus', $stored['models']); // unknown task dropped
    }

    public function test_health_is_graceful_when_provider_down(): void
    {
        $this->actingWithPermissions(['settings.manage']);

        $this->getJson('/api/ai/health')
            ->assertOk()
            ->assertJsonPath('data.reachable', false)
            ->assertJsonPath('data.models', []);
    }

    public function test_requires_settings_manage_permission(): void
    {
        $this->actingWithPermissions([]); // authenticated but no permission

        $this->getJson('/api/ai/config')->assertForbidden();
    }

    public function test_project_update_persists_ai_config(): void
    {
        Config::set('airr.edition', 'standard');
        $this->actingWithPermissions(['projects.manage', 'projects.view']);
        $project = Project::create(['code' => 'PRJ-1', 'name' => 'Test', 'status' => 'active']);

        $this->putJson("/api/projects/{$project->id}", [
            'code' => 'PRJ-1',
            'name' => 'Test',
            'ai_config' => ['provider' => 'ollama', 'models' => ['generation' => 'llama3.2:3b']],
        ])->assertOk()->assertJsonPath('data.ai_config.models.generation', 'llama3.2:3b');

        $this->assertSame('llama3.2:3b', $project->fresh()->ai_config['models']['generation']);
    }
}
