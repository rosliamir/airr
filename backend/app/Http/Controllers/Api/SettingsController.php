<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Setting;
use App\Services\AuditService;
use App\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

// FR-M14.4: global settings — regional preferences (editable), subscription
// limits/features (edition-derived, read-only), and an About panel.
class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    // Sensible Malaysian-sovereign defaults.
    private const REGIONAL_DEFAULTS = [
        'region'        => 'Malaysia',
        'currency'      => 'MYR',
        'currency_symbol' => 'RM',
        'locale'        => 'en-MY',
        'timezone'      => 'Asia/Kuala_Lumpur',
        'date_format'   => 'DD/MM/YYYY',
        'number_format' => '1,234.56',
        'data_residency' => 'Malaysia (sovereign)',
    ];

    public function index(): JsonResponse
    {
        $edition = Edition::current();
        $features = Config::get('airr.features', []);

        return $this->sendOk([
            'regional'     => array_merge(self::REGIONAL_DEFAULTS, Setting::get('regional', [])),
            'subscription' => [
                'edition'  => $edition,
                'limits'   => Config::get("airr.limits.{$edition}", []),
                'features' => collect($features)->map(fn ($eds, $key) => [
                    'key'     => $key,
                    'enabled' => in_array($edition, $eds, true),
                    'editions' => $eds,
                ])->values(),
            ],
            'about' => [
                'name'         => 'AIRR — AI & RAG Reporting',
                'motto'        => 'Thin & light, but powerful.',
                'version'      => 'MVP 1.0 (Standard Edition)',
                'edition'      => $edition,
                'website'      => 'airr.technology',
                'sovereignty'  => 'On-premise / sovereign. All inference via local Ollama; no external AI APIs; air-gap capable.',
                'stack'        => ['Vue + Tailwind', 'Laravel', 'PostgreSQL + pgvector + pgai', 'rag_api (FastAPI)', 'Ollama'],
                'brand_colors' => ['#FB7185', '#E11D48', '#9F1239'],
            ],
        ]);
    }

    // Persist regional preferences (settings.manage).
    public function updateRegional(Request $request): JsonResponse
    {
        $data = $request->validate([
            'region'         => 'nullable|string|max:80',
            'currency'       => 'nullable|string|max:8',
            'currency_symbol' => 'nullable|string|max:8',
            'locale'         => 'nullable|string|max:16',
            'timezone'       => 'nullable|string|max:64',
            'date_format'    => 'nullable|string|max:24',
            'number_format'  => 'nullable|string|max:24',
            'data_residency' => 'nullable|string|max:80',
        ]);

        $merged = array_merge(self::REGIONAL_DEFAULTS, Setting::get('regional', []), array_filter($data, fn ($v) => $v !== null));
        Setting::put('regional', $merged);
        $this->audit->log('settings.regional_updated', Setting::class, 'regional', null, $merged);

        return $this->sendOk($merged);
    }
}
