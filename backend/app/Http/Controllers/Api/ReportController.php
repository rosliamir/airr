<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Report;
use App\Services\AuditService;
use App\Services\ConnectorService;
use App\Services\Reporting\ReportRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// M6 (FR-M6.1) — report definitions. Read gated by reports.view; create/edit by
// reports.create / reports.edit. Owner+project visibility scoping.
class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuditService $audit,
        protected ConnectorService $connector,
        protected ReportRenderer $renderer,
    ) {}

    // FR-M6.2 — execute a report: fetch dataset rows (with runtime params) and
    // render deterministic HTML. reports.run.
    public function run(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'run');
        $params = (array) $request->input('params', []);

        $data = ['columns' => [], 'rows' => []];
        if ($report->dataset) {
            $result = $this->connector->run($report->dataset, $params);
            $data = ['columns' => $result['columns'] ?? [], 'rows' => $result['rows'] ?? []];
        }

        $html = $this->renderer->render($report, $data);
        $this->audit->log('report.run', Report::class, $report->id, null, ['rows' => count($data['rows'])]);

        return $this->sendOk([
            'html'      => $html,
            'columns'   => $data['columns'],
            'row_count' => count($data['rows']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Report::query()->with(['project:id,code,name', 'dataset:id,name', 'creator:id,name']);

        $viewer = $request->user();
        if (! $viewer->seesEverything()) {
            $query->accessibleTo($viewer); // per-report ACL (FR-M6 ACL)
        }
        if ($pid = $request->input('project_id')) {
            $query->where('project_id', $pid);
        }

        return $this->sendOk($query->orderByDesc('updated_at')->get()->map(fn ($r) => $this->row($r)));
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'view');

        return $this->sendOk($this->row($report->load(['project:id,code,name', 'dataset:id,name', 'roles:id,slug,name']), true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateReport($request);
        $report = Report::create([
            ...collect($data)->except('permissions')->all(),
            'definition' => $data['definition'] ?? $this->blankDefinition($data['type'] ?? 'table'),
            'created_by' => $request->user()->id,
        ]);
        $report->syncPermissions($data['permissions'] ?? []);
        $this->audit->log('report.created', Report::class, $report->id, null, ['name' => $report->name]);

        return $this->sendCreated($this->row($report->fresh(['project', 'dataset', 'roles'])));
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        $data = $this->validateReport($request, $report);
        $report->update(collect($data)->except('permissions')->all());
        if (array_key_exists('permissions', $data)) {
            $report->syncPermissions($data['permissions'] ?? []);
        }
        $this->audit->log('report.updated', Report::class, $report->id);

        return $this->sendOk($this->row($report->fresh(['project', 'dataset', 'roles']), true));
    }

    public function destroy(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        $report->delete();
        $this->audit->log('report.deleted', Report::class, $report->id);

        return $this->sendNoContent();
    }

    // Per-report ACL gate (FR-M6 ACL) — abort 403 if the user lacks the ability.
    private function authorizeReport(Request $request, Report $report, string $ability): void
    {
        if (! $report->allows($request->user(), $ability)) {
            abort(response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => "You do not have {$ability} access to this report."],
            ], 403));
        }
    }

    private function validateReport(Request $request, ?Report $report = null): array
    {
        return $request->validate([
            'name'            => 'required|string|max:160',
            'description'     => 'nullable|string|max:1000',
            'type'            => ['nullable', Rule::in(Report::TYPES)],
            'project_id'      => 'nullable|integer|exists:projects,id',
            'dataset_id'      => 'nullable|integer|exists:datasets,id',
            'status'          => ['nullable', Rule::in([Report::STATUS_DRAFT, Report::STATUS_PUBLISHED])],
            // Definition is flexible JSON; validate the parts the renderer relies on.
            'definition'             => 'nullable|array',
            'definition.type'        => ['nullable', Rule::in(Report::TYPES)],
            'definition.columns'     => 'nullable|array',
            'definition.columns.*.field' => 'required_with:definition.columns|string|max:120',
            'definition.groups'      => 'nullable|array',
            'definition.aggregates'  => 'nullable|array',
            'definition.filters'     => 'nullable|array',
            'definition.sorts'       => 'nullable|array',
            'definition.params'      => 'nullable|array',
            'definition.conditional' => 'nullable|array',
            // Per-report ACL grants (FR-M6 ACL).
            'permissions'           => 'nullable|array',
            'permissions.*.role_id' => 'required_with:permissions|integer|exists:roles,id',
            'permissions.*.view'    => 'nullable|boolean',
            'permissions.*.edit'    => 'nullable|boolean',
            'permissions.*.run'     => 'nullable|boolean',
        ]);
    }

    private function blankDefinition(string $type): array
    {
        return ['type' => $type, 'columns' => [], 'groups' => [], 'aggregates' => [], 'sorts' => [], 'params' => []];
    }

    private function row(Report $r, bool $withDefinition = false): array
    {
        return array_filter([
            'id'          => $r->id,
            'name'        => $r->name,
            'description' => $r->description,
            'type'        => $r->type,
            'status'      => $r->status,
            'project'     => $r->project ? ['id' => $r->project->id, 'code' => $r->project->code, 'name' => $r->project->name] : null,
            'dataset'     => $r->dataset ? ['id' => $r->dataset->id, 'name' => $r->dataset->name] : null,
            'creator'     => $r->creator ? ['id' => $r->creator->id, 'name' => $r->creator->name] : null,
            'definition'  => $withDefinition ? ($r->definition ?? []) : null,
            'permissions' => $withDefinition && $r->relationLoaded('roles')
                ? $r->roles->map(fn ($role) => [
                    'role_id' => $role->id,
                    'name'    => $role->name,
                    'view'    => (bool) $role->pivot->can_view,
                    'edit'    => (bool) $role->pivot->can_edit,
                    'run'     => (bool) $role->pivot->can_run,
                ])->values()
                : null,
            'updated_at'  => $r->updated_at,
        ], fn ($v) => $v !== null);
    }
}
