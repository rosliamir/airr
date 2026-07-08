<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Setting;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;
use App\Services\AuditService;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

// FR-M14.6 / FR-M15.6-7: AI provider & model administration.
//   - View/edit system-default model per task (embedding/generation/reasoning/audit)
//   - Health-check the active provider and list locally available models
class AiController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuditService $audit,
        protected ModelResolver $resolver,
        protected AiProvider $provider,
    ) {}

    const HOSTED_PROVIDERS = ['openai', 'claude'];

    public function index(): JsonResponse
    {
        $stored = Setting::get('ai', []);
        $hosted = [];
        foreach (self::HOSTED_PROVIDERS as $p) {
            $hosted[$p] = [
                'base_url'    => $stored["{$p}_base_url"] ?? Config::get("ai.providers.{$p}.base_url"),
                'api_key_set' => ! empty($stored["{$p}_api_key"]), // never expose the raw key
            ];
        }

        return $this->sendOk([
            'provider'            => $this->provider->name(),
            'available_providers' => array_merge(['ollama'], self::HOSTED_PROVIDERS),
            'hosted'              => $hosted,
            'tasks'               => Config::get('ai.tasks', []),
            'defaults'            => $this->resolver->systemDefaults(),
            'per_project'         => Edition::allows('project_ai_config'), // UI shows override only when allowed
            'edition'             => Edition::current(),
        ]);
    }

    // Persist admin-chosen system defaults (settings.manage).
    public function updateDefaults(Request $request): JsonResponse
    {
        $tasks = (array) Config::get('ai.tasks', []);
        $data = $request->validate([
            'provider'           => ['nullable', 'string', 'max:40', Rule::in(array_merge(['ollama'], self::HOSTED_PROVIDERS))],
            'models'             => 'nullable|array',
            'models.embedding'   => 'nullable|string|max:120',
            'models.generation'  => 'nullable|string|max:120',
            'models.reasoning'   => 'nullable|string|max:120',
            'models.audit'       => 'nullable|string|max:120',
            'models.retrieval'   => 'nullable|string|max:120',
            'models.compliance'  => 'nullable|string|max:120',
            'openai_base_url'    => 'nullable|string|max:255',
            'openai_api_key'     => 'nullable|string|max:255',
            'claude_base_url'    => 'nullable|string|max:255',
            'claude_api_key'     => 'nullable|string|max:255',
        ]);

        // Keep only known task keys.
        $models = array_intersect_key($data['models'] ?? [], array_flip($tasks));
        $existing = Setting::get('ai', []);
        $value = ['provider' => $data['provider'] ?? null, 'models' => $models];
        foreach (self::HOSTED_PROVIDERS as $p) {
            $value["{$p}_base_url"] = $data["{$p}_base_url"] ?? ($existing["{$p}_base_url"] ?? null);
            // Only overwrite a stored key if a new one was actually submitted —
            // the frontend never re-sends the raw key once set (index() masks it).
            $value["{$p}_api_key"] = ! empty($data["{$p}_api_key"])
                ? $data["{$p}_api_key"]
                : ($existing["{$p}_api_key"] ?? null);
        }
        Setting::put('ai', $value);
        $auditSafe = array_diff_key($value, array_flip(array_map(fn ($p) => "{$p}_api_key", self::HOSTED_PROVIDERS)));
        $this->audit->log('settings.ai_updated', Setting::class, 'ai', null, $auditSafe);

        return $this->sendOk(['defaults' => $this->resolver->systemDefaults()]);
    }

    // Live provider health + installed models (FR-M15.7). Best-effort: returns
    // reachable=false rather than erroring when Ollama is down.
    public function health(): JsonResponse
    {
        $reachable = $this->provider->health();

        return $this->sendOk([
            'provider'  => $this->provider->name(),
            'reachable' => $reachable,
            'models'    => $reachable ? $this->provider->listModels() : [],
        ]);
    }
}
