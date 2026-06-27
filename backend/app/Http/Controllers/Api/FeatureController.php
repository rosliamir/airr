<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\FeatureStatus;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Module readiness board (Dashboard drill-down). Merges build facts from
// config/features.php with reviewer-toggled status (feature_statuses).
class FeatureController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    // Per-module roll-up counts for the dashboard cards.
    public function summary(): JsonResponse
    {
        $statuses = FeatureStatus::all()->keyBy('feature_key');
        $out = [];
        foreach ((array) config('features', []) as $code => $module) {
            $features = $module['features'] ?? [];
            $out[$code] = [
                'total'          => count($features),
                'developed'      => collect($features)->where('developed', true)->count(),
                'ready_for_prod' => collect($features)->filter(fn ($f) => $statuses[$f['key']]->ready_for_prod ?? false)->count(),
            ];
        }

        return $this->sendOk($out);
    }

    // Feature checklist for one module.
    public function module(string $code): JsonResponse
    {
        $module = config("features.{$code}");
        if (! $module) {
            return $this->sendOk(['code' => $code, 'name' => $code, 'features' => []]);
        }

        $statuses = FeatureStatus::whereIn('feature_key', collect($module['features'])->pluck('key'))->get()->keyBy('feature_key');

        return $this->sendOk([
            'code'     => $code,
            'name'     => $module['name'],
            'features' => collect($module['features'])->map(fn ($f) => [
                'key'            => $f['key'],
                'fr'             => $f['fr'],
                'label'          => $f['label'],
                'developed'      => (bool) $f['developed'],
                'ai_tested'      => (bool) $f['ai_tested'],
                'human_tested'   => (bool) ($statuses[$f['key']]->human_tested ?? false),
                'ready_for_prod' => (bool) ($statuses[$f['key']]->ready_for_prod ?? false),
                'note'           => $statuses[$f['key']]->note ?? null,
            ])->values(),
        ]);
    }

    // Toggle reviewer status for one feature (settings.manage).
    public function update(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'human_tested'   => 'nullable|boolean',
            'ready_for_prod' => 'nullable|boolean',
            'note'           => 'nullable|string|max:200',
        ]);

        $status = FeatureStatus::updateOrCreate(
            ['feature_key' => $key],
            array_merge($data, ['updated_by' => $request->user()->id]),
        );
        $this->audit->log('feature.status_updated', FeatureStatus::class, $key, null, $data);

        return $this->sendOk([
            'key'            => $key,
            'human_tested'   => $status->human_tested,
            'ready_for_prod' => $status->ready_for_prod,
            'note'           => $status->note,
        ]);
    }
}
