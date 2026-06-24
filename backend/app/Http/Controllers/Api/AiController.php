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

    public function index(): JsonResponse
    {
        return $this->sendOk([
            'provider'        => $this->provider->name(),
            'tasks'           => Config::get('ai.tasks', []),
            'defaults'        => $this->resolver->systemDefaults(),
            'per_project'     => Edition::allows('project_ai_config'), // UI shows override only when allowed
            'edition'         => Edition::current(),
        ]);
    }

    // Persist admin-chosen system defaults (settings.manage).
    public function updateDefaults(Request $request): JsonResponse
    {
        $tasks = (array) Config::get('ai.tasks', []);
        $data = $request->validate([
            'provider'        => 'nullable|string|max:40',
            'models'          => 'nullable|array',
            'models.embedding'  => 'nullable|string|max:120',
            'models.generation' => 'nullable|string|max:120',
            'models.reasoning'  => 'nullable|string|max:120',
            'models.audit'      => 'nullable|string|max:120',
        ]);

        // Keep only known task keys.
        $models = array_intersect_key($data['models'] ?? [], array_flip($tasks));
        $value = ['provider' => $data['provider'] ?? null, 'models' => $models];
        Setting::put('ai', $value);
        $this->audit->log('settings.ai_updated', Setting::class, 'ai', null, $value);

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
