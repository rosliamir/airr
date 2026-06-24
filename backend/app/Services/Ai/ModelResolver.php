<?php

namespace App\Services\Ai;

use App\Models\Project;
use App\Models\Setting;
use App\Support\Edition;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Single point of model resolution (FR-M15.8). All AI callers (M3 embedding/
 * retrieval, M4 agents) MUST resolve through here and NEVER reference a model
 * name directly.
 *
 * Hierarchy:  Project override (projects.ai_config, FR-M14.5)  →  system default (config/ai.php).
 * Per-project override is edition-gated (`project_ai_config`): Community always
 * uses the system default regardless of any stored ai_config.
 */
class ModelResolver
{
    /**
     * Resolve the {provider, model} pair to use for a task, honouring an
     * optional project override.
     *
     * @return array{provider:string, model:string}
     */
    public function resolve(string $task, ?Project $project = null): array
    {
        $this->assertTask($task);

        $defaults = $this->systemDefaults();
        $provider = $defaults['provider'];
        $model = (string) ($defaults['models'][$task] ?? '');

        $override = $this->projectConfig($project);
        if ($override) {
            $provider = $override['provider'] ?? $provider;
            $model = data_get($override, "models.{$task}") ?: $model;
        }

        return ['provider' => $provider, 'model' => $model];
    }

    /** Convenience: just the model name for a task. */
    public function model(string $task, ?Project $project = null): string
    {
        return $this->resolve($task, $project)['model'];
    }

    /**
     * Effective system defaults: admin-editable Setting('ai') layered over the
     * static config/ai.php baseline (FR-M15.6 / FR-M14.6).
     *
     * @return array{provider:string, models:array<string,string>}
     */
    public function systemDefaults(): array
    {
        $stored = Setting::get('ai', []);

        return [
            'provider' => $stored['provider'] ?? (string) Config::get('ai.provider', 'ollama'),
            'models'   => array_merge(
                (array) Config::get('ai.defaults', []),
                array_filter((array) ($stored['models'] ?? []), fn ($v) => $v !== null && $v !== ''),
            ),
        ];
    }

    /**
     * Per-project AI config, but only when the edition permits overrides and a
     * non-empty config is present. Otherwise null (→ system default).
     *
     * @return array<string,mixed>|null
     */
    private function projectConfig(?Project $project): ?array
    {
        if (! $project || ! Edition::allows('project_ai_config')) {
            return null;
        }

        $config = $project->ai_config;

        return is_array($config) && $config !== [] ? $config : null;
    }

    private function assertTask(string $task): void
    {
        $tasks = (array) Config::get('ai.tasks', []);
        if (! in_array($task, $tasks, true)) {
            throw new InvalidArgumentException("Unknown AI task [{$task}]. Expected one of: " . implode(', ', $tasks));
        }
    }
}
