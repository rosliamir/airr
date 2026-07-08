<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Report;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;
use App\Services\AuditService;
use App\Services\ConnectorService;
use App\Services\Reporting\ReportRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            try {
                $result = $this->connector->run($report->dataset, $params);
            } catch (\Throwable $e) {
                return $this->sendError(503, 'DATASOURCE_UNAVAILABLE', 'Could not reach the data source: ' . $e->getMessage());
            }
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
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }
        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        $sort = $request->input('sort', '-updated_at'); // e.g. name, -name, updated_at, -updated_at, status
        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        if (in_array($column, ['name', 'updated_at', 'status', 'type'], true)) {
            $query->orderBy($column, $direction);
        } else {
            $query->orderByDesc('updated_at');
        }

        return $this->sendOk($query->get()->map(fn ($r) => $this->row($r)));
    }

    // Clone a report — new draft copy, same definition/dataset/type, name suffixed.
    public function duplicate(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'view');

        $copy = Report::create([
            'project_id'  => $report->project_id,
            'dataset_id'  => $report->dataset_id,
            'name'        => $report->name . ' (Copy)',
            'description' => $report->description,
            'type'        => $report->type,
            'definition'  => $report->definition,
            'status'      => Report::STATUS_DRAFT,
            'created_by'  => $request->user()->id,
        ]);
        $this->audit->log('report.duplicated', Report::class, $copy->id, null, ['from' => $report->id]);

        return $this->sendCreated($this->row($copy->fresh(['project', 'dataset'])));
    }

    // Hide from the default list without deleting (distinct from soft-delete).
    public function archive(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        $report->update(['archived_at' => now()]);
        $this->audit->log('report.archived', Report::class, $report->id);

        return $this->sendOk($this->row($report->fresh(['project', 'dataset'])));
    }

    public function unarchive(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        $report->update(['archived_at' => null]);
        $this->audit->log('report.unarchived', Report::class, $report->id);

        return $this->sendOk($this->row($report->fresh(['project', 'dataset'])));
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $report = Report::withTrashed()->findOrFail($id);
        $this->authorizeReport($request, $report, 'edit');
        $report->restore();
        $this->audit->log('report.restored', Report::class, $report->id);

        return $this->sendOk($this->row($report->fresh(['project', 'dataset'])));
    }

    // Download the report as a portable JSON file (definition + metadata).
    public function export(Request $request, Report $report): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeReport($request, $report, 'view');

        $payload = [
            'name'        => $report->name,
            'description' => $report->description,
            'type'        => $report->type,
            'definition'  => $report->definition ?? [],
            'exported_at' => now()->toIso8601String(),
        ];

        $filename = \Illuminate\Support\Str::slug($report->name) . '.json';

        return response()->json($payload)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    // Create a new report from a previously-exported JSON payload.
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file'            => 'nullable|file|mimes:json,txt|max:2048',
            'payload'         => 'nullable|array',
            'payload.name'    => 'required_with:payload|string|max:160',
            'project_id'      => 'nullable|integer|exists:projects,id',
        ]);

        if ($request->hasFile('file')) {
            $decoded = json_decode($request->file('file')->get(), true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return $this->sendError(422, 'INVALID_JSON', 'The uploaded file is not valid JSON.');
            }
        } else {
            $decoded = $data['payload'] ?? null;
        }

        if (! $decoded || empty($decoded['name'])) {
            return $this->sendError(422, 'INVALID_PAYLOAD', 'Provide a file or payload with at least a "name".');
        }

        $report = Report::create([
            'project_id'  => $data['project_id'] ?? null,
            'name'        => $decoded['name'],
            'description' => $decoded['description'] ?? null,
            'type'        => in_array($decoded['type'] ?? null, Report::TYPES, true) ? $decoded['type'] : 'table',
            'definition'  => $decoded['definition'] ?? $this->blankDefinition($decoded['type'] ?? 'table'),
            'status'      => Report::STATUS_DRAFT,
            'created_by'  => $request->user()->id,
        ]);
        $this->audit->log('report.imported', Report::class, $report->id);

        return $this->sendCreated($this->row($report->fresh(['project', 'dataset'])));
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'view');

        return $this->sendOk($this->row($report->load(['project:id,code,name', 'dataset:id,name', 'roles:id,slug,name', 'templates:id,name']), true));
    }

    // Preview with sample/placeholder data derived from the dataset's declared
    // fields — no live datasource call, for quickly checking layout.
    public function preview(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'run');

        $fields = $report->dataset?->fields ?? [];
        if (! $fields) {
            $data = ['columns' => ['sample'], 'rows' => [['sample' => 'Sample value']]];
        } else {
            $columns = collect($fields)->pluck('name')->filter()->values()->all();
            $sampleRow = collect($fields)->mapWithKeys(fn ($f) => [$f['name'] => $this->sampleValueFor($f['type'] ?? 'string', $f['label'] ?? $f['name'])])->all();
            $data = ['columns' => $columns, 'rows' => array_fill(0, 3, $sampleRow)];
        }

        $html = $this->renderer->render($report, $data);

        return $this->sendOk(['html' => $html, 'columns' => $data['columns'], 'row_count' => count($data['rows']), 'preview' => true]);
    }

    private function sampleValueFor(string $type, string $label): mixed
    {
        return match ($type) {
            'number', 'integer', 'float' => 123,
            'date' => now()->format('Y-m-d'),
            'boolean' => true,
            default => "Sample {$label}",
        };
    }

    // BA/SA-facing: describe the desired report in plain language (e.g. "switch
    // this to a form layout", "remove the email column", "add a running total")
    // and have the AI rewrite the definition JSON accordingly.
    public function generateFromPrompt(Request $request, Report $report, ModelResolver $resolver, AiProvider $ai): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        if ($report->locked) {
            return $this->sendError(423, 'REPORT_LOCKED', 'This report is locked. Unlock it first.');
        }
        $data = $request->validate(['prompt' => 'required|string|max:2000']);

        $model = $resolver->model('generation');
        $system = 'You edit a report definition JSON for a reporting tool. The definition has keys: '
            . 'type (one of table/grouped/kpi/matrix/chart/document), columns (array of {field,label}), '
            . 'groups (array), aggregates (array), filters (array), sorts (array), conditional (array). '
            . 'You will be given the CURRENT definition JSON and an instruction. '
            . 'Return ONLY the complete, updated definition as valid JSON — no markdown fences, no explanation.';
        $userMessage = "Current definition:\n" . json_encode($report->definition ?? [], JSON_PRETTY_PRINT)
            . "\n\nInstruction: {$data['prompt']}";

        try {
            $raw = $ai->generate($model, $userMessage, ['system' => $system, 'temperature' => 0.2]);
        } catch (\Throwable $e) {
            return $this->sendError(503, 'AI_UNAVAILABLE', 'Could not reach the AI provider: ' . $e->getMessage());
        }

        $decoded = $this->extractJsonObject($raw);

        if ($decoded === null) {
            return $this->sendError(422, 'AI_RESPONSE_INVALID', 'The AI did not return valid JSON. Try rephrasing the instruction.', ['raw' => mb_substr($raw, 0, 1000)]);
        }

        // The AI only reasons about type/columns/groups/etc — carry over the
        // report-level fields it was never asked about, and record the
        // instruction that produced this version.
        $existing = (array) ($report->definition ?? []);
        $decoded['prompt'] = $data['prompt'];
        $decoded['fixed_parameters_enabled'] = $existing['fixed_parameters_enabled'] ?? [];
        $decoded['custom_parameters'] = $existing['custom_parameters'] ?? [];

        $report->update(['definition' => $decoded]);
        $this->audit->log('report.ai_generated', Report::class, $report->id, null, ['prompt' => $data['prompt']]);
        $this->saveHistory($report, $request->user()->id, 'ai_generated', $data['prompt']);

        return $this->sendOk(['definition' => $decoded, 'report' => $this->row($report->fresh(), true)]);
    }

    // AI models frequently wrap JSON in markdown fences or add stray prose even
    // when told not to — tolerate both instead of hard-failing on the first
    // json_decode() attempt.
    private function extractJsonObject(string $raw): ?array
    {
        $trimmed = trim($raw);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $trimmed, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    // Bump version + set status=published every time the report is compiled/published.
    public function publish(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        if ($report->locked) {
            return $this->sendError(423, 'REPORT_LOCKED', 'This report is locked. Unlock it first.');
        }
        $report->update(['status' => Report::STATUS_PUBLISHED, 'version' => $report->version + 1]);
        $this->audit->log('report.published', Report::class, $report->id, null, ['version' => $report->version]);
        $this->saveHistory($report, $request->user()->id, 'published');

        return $this->sendOk($this->row($report->fresh(['project', 'dataset'])));
    }

    public function lock(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        $report->update(['locked' => true]);
        $this->audit->log('report.locked', Report::class, $report->id);

        return $this->sendOk($this->row($report->fresh(['project', 'dataset'])));
    }

    public function unlock(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        $report->update(['locked' => false]);
        $this->audit->log('report.unlocked', Report::class, $report->id);

        return $this->sendOk($this->row($report->fresh(['project', 'dataset'])));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateReport($request);
        $report = Report::create([
            ...collect($data)->except(['permissions', 'templates'])->all(),
            'definition' => $data['definition'] ?? $this->blankDefinition($data['type'] ?? 'table'),
            'created_by' => $request->user()->id,
        ]);
        $report->syncPermissions($data['permissions'] ?? []);
        if (! empty($data['templates'])) {
            $report->templates()->sync($data['templates']);
        }
        if (! empty($data['project_id'])) {
            $report->projects()->syncWithoutDetaching([$data['project_id']]); // link to home project
        }
        $this->audit->log('report.created', Report::class, $report->id, null, ['name' => $report->name]);

        return $this->sendCreated($this->row($report->fresh(['project', 'dataset', 'roles', 'templates'])));
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        if ($report->locked) {
            return $this->sendError(423, 'REPORT_LOCKED', 'This report is locked. Unlock it first.');
        }
        $data = $this->validateReport($request, $report);
        $report->update(collect($data)->except(['permissions', 'templates'])->all());
        if (array_key_exists('permissions', $data)) {
            $report->syncPermissions($data['permissions'] ?? []);
        }
        if (array_key_exists('templates', $data)) {
            $report->templates()->sync($data['templates'] ?? []);
        }
        $this->audit->log('report.updated', Report::class, $report->id);
        $this->saveHistory($report, $request->user()->id, 'updated');

        return $this->sendOk($this->row($report->fresh(['project', 'dataset', 'roles', 'templates']), true));
    }

    public function destroy(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        $report->delete();
        $this->audit->log('report.deleted', Report::class, $report->id);

        return $this->sendNoContent();
    }

    public function history(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'view');

        $rows = DB::table('report_histories as h')
            ->join('users as u', 'u.id', '=', 'h.changed_by')
            ->where('h.report_id', $report->id)
            ->orderByDesc('h.created_at')
            ->limit(50)
            ->get(['h.id', 'h.action', 'h.snapshot', 'h.created_at', 'u.name as changed_by_name']);

        return $this->sendOk($rows->map(fn ($r) => [
            'id'              => $r->id,
            'action'          => $r->action,
            'snapshot'        => json_decode($r->snapshot, true),
            'changed_by_name' => $r->changed_by_name,
            'created_at'      => $r->created_at,
        ]));
    }

    public function logs(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'view');

        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.subject_type', Report::class)
            ->where('a.subject_id', $report->id)
            ->orderByDesc('a.created_at')
            ->limit(100)
            ->get(['a.id', 'a.event', 'a.properties', 'a.created_at', 'u.name as user_name']);

        return $this->sendOk($rows->map(fn ($r) => [
            'id'         => $r->id,
            'event'      => $r->event,
            'properties' => json_decode($r->properties ?? '{}', true),
            'user_name'  => $r->user_name ?? 'System',
            'created_at' => $r->created_at,
        ]));
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
            'layout'          => ['nullable', Rule::in(Report::LAYOUTS)],
            'printout_size'   => ['nullable', Rule::in(Report::PRINTOUT_SIZES)],
            'printout_width'  => 'nullable|integer|min:1|max:5000',
            'printout_height' => 'nullable|integer|min:1|max:5000',
            'output_formats'        => 'nullable|array',
            'output_formats.*'      => Rule::in(Report::OUTPUT_FORMATS),
            'templates'             => 'nullable|array',
            'templates.*'           => 'integer|exists:templates,id',
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
            'definition.prompt'      => 'nullable|string|max:2000',
            // Report-level parameters (left panel > Parameters): a toggle map for the
            // dataset's fixed params, plus report-defined custom parameters.
            'definition.fixed_parameters_enabled'          => 'nullable|array',
            'definition.custom_parameters'                 => 'nullable|array',
            'definition.custom_parameters.*.id'             => 'nullable|string|max:60',
            'definition.custom_parameters.*.title'          => 'required_with:definition.custom_parameters|string|max:160',
            'definition.custom_parameters.*.type'           => ['required_with:definition.custom_parameters', Rule::in(['text', 'dropdown', 'checkbox', 'radio'])],
            'definition.custom_parameters.*.default_value'  => 'nullable',
            'definition.custom_parameters.*.options'        => 'nullable|array',
            'definition.custom_parameters.*.data_source_id' => 'nullable|integer|exists:data_sources,id',
            'definition.custom_parameters.*.dataset_id'     => 'nullable|integer|exists:datasets,id',
            'definition.custom_parameters.*.data_column'    => 'nullable|string|max:120',
            'definition.custom_parameters.*.remark'         => 'nullable|string|max:500',
            'definition.custom_parameters.*.enabled'        => 'nullable|boolean',
            // Per-report ACL grants (FR-M6 ACL).
            'permissions'           => 'nullable|array',
            'permissions.*.role_id' => 'required_with:permissions|integer|exists:roles,id',
            'permissions.*.view'    => 'nullable|boolean',
            'permissions.*.edit'    => 'nullable|boolean',
            'permissions.*.run'     => 'nullable|boolean',
        ]);
    }

    private function saveHistory(Report $report, int $userId, string $action, ?string $prompt = null): void
    {
        DB::table('report_histories')->insert([
            'report_id'   => $report->id,
            'changed_by'  => $userId,
            'action'      => $action,
            'snapshot'    => json_encode([
                'name'       => $report->name,
                'type'       => $report->type,
                'status'     => $report->status,
                'definition' => $report->definition ?? [],
                'prompt'     => $prompt,
            ]),
            'created_at'  => now(),
            'updated_at'  => now(),
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
            'version'     => $r->version,
            'layout'      => $r->layout,
            'printout_size'   => $r->printout_size,
            'printout_width'  => $r->printout_width,
            'printout_height' => $r->printout_height,
            'output_formats'  => $r->output_formats ?? [],
            'locked'      => (bool) $r->locked,
            'project'     => $r->project ? ['id' => $r->project->id, 'code' => $r->project->code, 'name' => $r->project->name] : null,
            'dataset'     => $r->dataset ? ['id' => $r->dataset->id, 'name' => $r->dataset->name] : null,
            'creator'     => $r->creator ? ['id' => $r->creator->id, 'name' => $r->creator->name] : null,
            'templates'   => $r->relationLoaded('templates') ? $r->templates->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values() : null,
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
            'archived_at' => $r->archived_at?->toISOString(),
            'deleted_at'  => $r->deleted_at?->toISOString(),
        ], fn ($v) => $v !== null);
    }
}
