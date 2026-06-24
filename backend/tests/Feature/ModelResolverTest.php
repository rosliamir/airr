<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use App\Services\Ai\ModelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// FR-M15.8 / FR-M14.5 — model resolution hierarchy.
class ModelResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): ModelResolver
    {
        return new ModelResolver();
    }

    public function test_resolves_system_default_per_task(): void
    {
        Config::set('ai.defaults.embedding', 'nomic-embed-text');

        $r = $this->resolver()->resolve('embedding');

        $this->assertSame('ollama', $r['provider']);
        $this->assertSame('nomic-embed-text', $r['model']);
    }

    public function test_project_override_applies_on_standard_edition(): void
    {
        Config::set('airr.edition', 'standard');
        $project = new Project(['ai_config' => ['models' => ['reasoning' => 'llama3.1:70b']]]);

        $this->assertSame('llama3.1:70b', $this->resolver()->model('reasoning', $project));
    }

    public function test_unset_task_falls_back_to_default(): void
    {
        Config::set('airr.edition', 'standard');
        Config::set('ai.defaults.embedding', 'nomic-embed-text');
        $project = new Project(['ai_config' => ['models' => ['reasoning' => 'llama3.1:70b']]]);

        $this->assertSame('nomic-embed-text', $this->resolver()->model('embedding', $project));
    }

    public function test_community_edition_ignores_project_override(): void
    {
        Config::set('airr.edition', 'community');
        Config::set('ai.defaults.reasoning', 'llama3.1:8b');
        $project = new Project(['ai_config' => ['models' => ['reasoning' => 'llama3.1:70b']]]);

        $this->assertSame('llama3.1:8b', $this->resolver()->model('reasoning', $project));
    }

    public function test_setting_overrides_config_in_system_defaults(): void
    {
        Config::set('ai.defaults.reasoning', 'llama3.1:8b');
        Setting::put('ai', ['provider' => 'ollama', 'models' => ['reasoning' => 'llama3.1:70b']]);

        $defaults = $this->resolver()->systemDefaults();

        $this->assertSame('llama3.1:70b', $defaults['models']['reasoning']);
    }

    public function test_unknown_task_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver()->resolve('bogus');
    }
}
