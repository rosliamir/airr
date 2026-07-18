<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Constant;
use App\Models\DataSource;
use App\Models\Dataset;
use App\Models\Report;
use App\Models\Template;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;
use App\Services\AuditService;
use App\Services\ConnectorService;
use App\Services\ConstantResolver;
use App\Services\Reporting\ConditionFilterApplier;
use App\Services\Reporting\DefinitionPromptEditor;
use App\Services\Reporting\ReportRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        protected ConstantResolver $constants,
        protected ConditionFilterApplier $conditionFilter,
    ) {}

    // FR-M6.2 — execute a report: fetch dataset rows (with runtime params) and
    // render deterministic HTML. reports.run.
    public function run(Request $request, Report $report, ModelResolver $resolver, AiProvider $ai): JsonResponse
    {
        $this->authorizeReport($request, $report, 'run');
        $params = (array) $request->input('params', []);
        $customParams = (array) $request->input('custom_params', []);
        // The Filter/condition (AI-applied) is a Preview-only concept — a
        // plain Run is just "show me the data as-is". The Preview modal
        // explicitly opts in via apply_filter=true.
        $applyFilter = $request->boolean('apply_filter');
        $this->applyDraftDefinition($request, $report);

        $data = ['columns' => [], 'rows' => []];
        $warning = null;
        if ($report->dataset) {
            // A filled-in parameter narrows the underlying query to a
            // meaningful, bounded slice — only then is it safe to fetch every
            // matching row. With no filter at all, an unbounded table (this
            // has happened with millions of rows) would time out or produce
            // an unrenderable result, so we cap it and say so instead.
            $isFiltered = $this->hasFilterValue($params);
            try {
                $result = $this->connector->run($report->dataset, $params, 1, 20, $isFiltered);
            } catch (\Throwable $e) {
                return $this->sendError(503, 'DATASOURCE_UNAVAILABLE', 'Could not reach the data source: ' . $e->getMessage());
            }
            $data = ['columns' => $result['columns'] ?? [], 'rows' => $result['rows'] ?? []];
            if ($applyFilter) {
                $data['rows'] = $this->conditionFilter->apply($report, $data['rows'], $resolver, $ai, $customParams);
            }
        }

        $html = $this->renderer->render($report, $data);
        $this->audit->log('report.run', Report::class, $report->id, null, ['rows' => count($data['rows'])]);

        return $this->sendOk([
            'html'      => $html,
            'columns'   => $data['columns'],
            'row_count' => count($data['rows']),
            'warning'   => $warning,
        ]);
    }

    // The linked header Template's "Parameter Screen" field (a welcome/intro
    // message meant for the parameter entry step) is a distinct field from
    // that same template's Header — it must NOT be mixed into the rendered
    // report output. Preview's Step 1 fetches it separately via this.
    public function parameterScreen(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'run');
        $this->applyDraftDefinition($request, $report);
        $def = $report->definition ?? [];
        $templateId = $def['template_header_id'] ?? null;
        $html = '';
        if ($templateId) {
            $template = Template::find($templateId);
            if ($template && $template->parameter_screen) {
                $html = $this->constants->resolve((string) $template->parameter_screen, $report->project_id ?? null, Auth::user(), null, null, $report->name);
            }
        }

        return $this->sendOk(['html' => $html]);
    }

    private function hasFilterValue(array $params): bool
    {
        foreach ($params as $value) {
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    // Preview-mode export: runs the report with the given (ticked) parameters,
    // same as run(), then streams the result as a real CSV or Excel file via
    // PhpSpreadsheet — using the exact same column/value resolution as the HTML
    // preview (ReportRenderer::tabularData), so the file matches what's on screen.
    public function exportFile(Request $request, Report $report, ModelResolver $resolver, AiProvider $ai): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeReport($request, $report, 'run');
        $data = $request->validate(['format' => 'required|in:csv,excel', 'params' => 'nullable|array', 'custom_params' => 'nullable|array', 'definition' => 'nullable|array']);
        $params = (array) ($data['params'] ?? []);
        $customParams = (array) ($data['custom_params'] ?? []);
        $this->applyDraftDefinition($request, $report);

        $tableData = ['columns' => [], 'rows' => []];
        if ($report->dataset) {
            try {
                $result = $this->connector->run($report->dataset, $params, 1, 20, $this->hasFilterValue($params));
            } catch (\Throwable $e) {
                return $this->sendError(503, 'DATASOURCE_UNAVAILABLE', 'Could not reach the data source: ' . $e->getMessage());
            }
            $tableData = ['columns' => $result['columns'] ?? [], 'rows' => $result['rows'] ?? []];
            $tableData['rows'] = $this->conditionFilter->apply($report, $tableData['rows'], $resolver, $ai, $customParams);
        }

        $table = $this->renderer->tabularData($report, $tableData);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($table['headers'], null, 'A1');
        if ($table['rows']) {
            $sheet->fromArray($table['rows'], null, 'A2');
        }

        $isCsv = $data['format'] === 'csv';
        $writer = $isCsv
            ? new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet)
            : new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = \Illuminate\Support\Str::slug($report->name) . ($isCsv ? '.csv' : '.xlsx');

        $this->audit->log('report.exported_file', Report::class, $report->id, null, ['format' => $data['format']]);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => $isCsv ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Report::query()->with(['project:id,code,name', 'dataset:id,name,data_source_id', 'creator:id,name']);

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

    // Download the report as a SELF-CONTAINED JSON bundle: the report definition
    // itself, plus everything it depends on to actually run somewhere else —
    // its dataset + data source (connection secrets redacted — see below),
    // attached templates, and every {{SCOPE:KEY}} constant referenced anywhere
    // in the definition or templates.
    public function export(Request $request, Report $report): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeReport($request, $report, 'view');
        $report->loadMissing(['dataset.dataSource', 'templates']);

        $payload = [
            'name'            => $report->name,
            'description'     => $report->description,
            'type'            => $report->type,
            'definition'      => $report->definition ?? [],
            'layout'          => $report->layout,
            'printout_size'   => $report->printout_size,
            'printout_width'  => $report->printout_width,
            'printout_height' => $report->printout_height,
            'output_formats'  => $report->output_formats ?? [],
            'exported_at'     => now()->toIso8601String(),
        ];

        $scanText = json_encode($report->definition ?? []);

        if ($report->dataset) {
            $ds = $report->dataset;
            $payload['dataset'] = [
                'name'        => $ds->name,
                'description' => $ds->description,
                'query'       => $ds->query,
                'method'      => $ds->method,
                'body'        => $ds->body,
                'fields'      => $ds->fields ?? [],
                'parameters'  => $ds->parameters ?? [],
            ];
            $scanText .= ' ' . $ds->query . ' ' . $ds->body;

            if ($ds->dataSource) {
                $payload['dataset']['data_source'] = [
                    'name'            => $ds->dataSource->name,
                    'type'            => $ds->dataSource->type,
                    'config'          => $this->redactSecrets($ds->dataSource->config ?? []),
                    'config_redacted' => true, // credentials are NEVER exported — re-enter them after import
                ];
            }
        }

        if ($report->templates->isNotEmpty()) {
            $payload['templates'] = $report->templates->map(function (Template $t) use (&$scanText) {
                $scanText .= ' ' . $t->header . ' ' . $t->body . ' ' . $t->footer . ' ' . $t->page_header . ' ' . $t->page_footer . ' ' . $t->parameter_screen;

                return [
                    'name'             => $t->name,
                    'description'      => $t->description,
                    'header'           => $t->header,
                    'body'             => $t->body,
                    'footer'           => $t->footer,
                    'page_header'      => $t->page_header,
                    'page_footer'      => $t->page_footer,
                    'parameter_screen' => $t->parameter_screen,
                    'groups'           => $t->groups ?? [],
                    'meta'             => $t->meta ?? [],
                ];
            })->values()->all();
        }

        $tokens = $this->scanConstantTokens($scanText);
        if ($tokens) {
            $query = Constant::query()->where(function ($q) use ($tokens) {
                foreach ($tokens as $t) {
                    $q->orWhere(fn ($qq) => $qq->where('scope', $t['scope'])->where('key', $t['key']));
                }
            });
            if ($report->project_id) {
                $query->where(fn ($q) => $q->where('scope', '!=', 'project')->orWhere('project_id', $report->project_id));
            } else {
                $query->where('scope', '!=', 'project');
            }
            $payload['constants'] = $query->get()->map(fn (Constant $c) => [
                'scope'       => $c->scope,
                'type'        => $c->type,
                'key'         => $c->key,
                'label'       => $c->label,
                'value'       => $c->value,
                'format'      => $c->format,
                'formula'     => $c->formula,
                'data_column' => $c->data_column,
            ])->values()->all();
        }

        $filename = \Illuminate\Support\Str::slug($report->name) . '.json';

        return response()->json($payload)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    // Never let a data source's connection secrets leave the server in an
    // export file — replace common credential keys with a placeholder so the
    // importer knows to re-enter them, while keeping harmless structural info
    // (host, port, database name, etc) so reconnecting is still easy.
    private function redactSecrets(array $config): array
    {
        $secretKeys = ['password', 'pass', 'secret', 'api_key', 'apikey', 'token', 'access_token', 'client_secret'];
        foreach ($config as $key => $value) {
            if (in_array(strtolower((string) $key), $secretKeys, true)) {
                $config[$key] = null;
            }
        }

        return $config;
    }

    // Find every {{SCOPE:KEY}} token referenced in the given text (report
    // definition JSON, template bodies, dataset query/body) so the exported
    // bundle can include exactly the constants this report actually needs.
    private function scanConstantTokens(string $text): array
    {
        preg_match_all('/\{\{(SYSTEM|GLOBAL|PROJECT):([A-Z0-9_]+)\}\}/', $text, $m, PREG_SET_ORDER);
        $tokens = [];
        foreach ($m as $match) {
            $tokens[] = ['scope' => strtolower($match[1]), 'key' => $match[2]];
        }

        return collect($tokens)->unique(fn ($t) => $t['scope'] . ':' . $t['key'])->values()->all();
    }

    // Create a new report from a previously-exported JSON bundle — recreates
    // (or reuses, matched by name) the dataset/data source, templates, and
    // constants it depends on, so the report is immediately usable on this
    // environment (data source credentials still need to be re-entered — see
    // export()'s redactSecrets()).
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file'            => 'nullable|file|mimes:json,txt|max:5120',
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

        $userId = $request->user()->id;
        $projectId = $data['project_id'] ?? null;
        $notes = [];

        // Constants: create any that don't already exist here (matched by scope+key,
        // and project_id for project-scoped ones).
        foreach ($decoded['constants'] ?? [] as $c) {
            $scope = in_array($c['scope'] ?? null, [Constant::SCOPE_SYSTEM, Constant::SCOPE_GLOBAL, Constant::SCOPE_PROJECT], true) ? $c['scope'] : Constant::SCOPE_GLOBAL;
            $exists = Constant::where('scope', $scope)->where('key', $c['key'])
                ->when($scope === Constant::SCOPE_PROJECT, fn ($q) => $q->where('project_id', $projectId))
                ->exists();
            if (! $exists && ! empty($c['key'])) {
                Constant::create([
                    'scope' => $scope, 'type' => $c['type'] ?? Constant::TYPE_TEXT,
                    'project_id' => $scope === Constant::SCOPE_PROJECT ? $projectId : null,
                    'key' => $c['key'], 'label' => $c['label'] ?? $c['key'], 'value' => $c['value'] ?? null,
                    'format' => $c['format'] ?? null, 'formula' => $c['formula'] ?? null,
                    'data_column' => $c['data_column'] ?? null, 'created_by' => $userId,
                ]);
                $notes[] = 'Created constant {{' . strtoupper($scope) . ':' . $c['key'] . '}}';
            }
        }

        // Dataset + data source: reuse an existing data source by name if one
        // exists, else create it (with redacted credentials the user must fill in).
        $datasetId = null;
        if ($ds = $decoded['dataset'] ?? null) {
            $dataSourceId = null;
            if ($src = $ds['data_source'] ?? null) {
                $existingSrc = DataSource::where('name', $src['name'])->first();
                if ($existingSrc) {
                    $dataSourceId = $existingSrc->id;
                } else {
                    $newSrc = DataSource::create([
                        'project_id' => $projectId, 'name' => $src['name'], 'type' => $src['type'],
                        'config' => $src['config'] ?? [], 'status' => 'active', 'created_by' => $userId,
                    ]);
                    $dataSourceId = $newSrc->id;
                    $notes[] = "Created data source \"{$src['name']}\" — re-enter its connection credentials before using it.";
                }
            }
            $existingDataset = $dataSourceId
                ? Dataset::where('data_source_id', $dataSourceId)->where('name', $ds['name'])->first()
                : null;
            if ($existingDataset) {
                $datasetId = $existingDataset->id;
            } elseif ($dataSourceId) {
                $newDataset = Dataset::create([
                    'data_source_id' => $dataSourceId, 'name' => $ds['name'], 'description' => $ds['description'] ?? null,
                    'query' => $ds['query'] ?? null, 'method' => $ds['method'] ?? null, 'body' => $ds['body'] ?? null,
                    'fields' => $ds['fields'] ?? [], 'parameters' => $ds['parameters'] ?? [], 'created_by' => $userId,
                ]);
                $datasetId = $newDataset->id;
                $notes[] = "Created dataset \"{$ds['name']}\"";
            }
        }

        $report = Report::create([
            'project_id'      => $projectId,
            'dataset_id'      => $datasetId,
            'name'            => $decoded['name'],
            'description'     => $decoded['description'] ?? null,
            'type'            => in_array($decoded['type'] ?? null, Report::TYPES, true) ? $decoded['type'] : 'table',
            'definition'      => $decoded['definition'] ?? $this->blankDefinition($decoded['type'] ?? 'table'),
            'layout'          => $decoded['layout'] ?? 'portrait',
            'printout_size'   => $decoded['printout_size'] ?? 'a4',
            'printout_width'  => $decoded['printout_width'] ?? null,
            'printout_height' => $decoded['printout_height'] ?? null,
            'output_formats'  => $decoded['output_formats'] ?? [],
            'status'          => Report::STATUS_DRAFT,
            'created_by'      => $userId,
        ]);

        // Templates: reuse by name if one already exists, else create + attach.
        $templateIds = [];
        foreach ($decoded['templates'] ?? [] as $t) {
            $existing = Template::where('name', $t['name'])->first();
            if ($existing) {
                $templateIds[] = $existing->id;
                continue;
            }
            $newTemplate = Template::create([
                'name' => $t['name'], 'description' => $t['description'] ?? null, 'project_id' => $projectId,
                'header' => $t['header'] ?? null, 'body' => $t['body'] ?? null, 'footer' => $t['footer'] ?? null,
                'page_header' => $t['page_header'] ?? null, 'page_footer' => $t['page_footer'] ?? null,
                'parameter_screen' => $t['parameter_screen'] ?? null, 'groups' => $t['groups'] ?? [],
                'meta' => $t['meta'] ?? [], 'created_by' => $userId,
            ]);
            $templateIds[] = $newTemplate->id;
            $notes[] = "Created template \"{$t['name']}\"";
        }
        if ($templateIds) {
            $report->templates()->sync($templateIds);
        }

        $this->audit->log('report.imported', Report::class, $report->id, null, ['notes' => $notes]);

        return $this->sendCreated($this->row($report->fresh(['project', 'dataset', 'templates']), true) + ['import_notes' => $notes]);
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'view');

        return $this->sendOk($this->row($report->load(['project:id,code,name', 'dataset:id,name,data_source_id', 'roles:id,slug,name', 'templates:id,name']), true));
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
    public function generateFromPrompt(Request $request, Report $report, ModelResolver $resolver, AiProvider $ai, DefinitionPromptEditor $editor): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        if ($report->locked) {
            return $this->sendError(423, 'REPORT_LOCKED', 'This report is locked. Unlock it first.');
        }
        $data = $request->validate([
            'prompt' => 'required|string|max:2000',
            'file'   => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,pdf,txt,md,csv',
        ]);

        // A bounded sample of real rows lets the AI ground a requested
        // narrative/analysis in actual values instead of guessing from column
        // names alone. Best-effort — if the dataset can't be reached, the
        // edit still proceeds without a sample.
        $sampleRows = null;
        if ($report->dataset) {
            try {
                $sampleRows = $this->connector->run($report->dataset, [], 1, 30)['rows'] ?? null;
            } catch (\Throwable) {
                $sampleRows = null;
            }
        }

        try {
            $decoded = $editor->apply(
                (array) ($report->definition ?? []),
                $data['prompt'],
                $request->file('file'),
                $resolver->model('generation'),
                $ai,
                $sampleRows,
            );
        } catch (\RuntimeException $e) {
            return $this->sendError(422, 'AI_RESPONSE_INVALID', 'The AI did not return valid JSON. Try rephrasing the instruction.');
        } catch (\Throwable $e) {
            return $this->sendError(503, 'AI_UNAVAILABLE', 'Could not reach the AI provider: ' . $e->getMessage());
        }

        $report->update(['definition' => $decoded, 'version' => $report->version + 1]);
        $this->audit->log('report.ai_generated', Report::class, $report->id, null, ['prompt' => $data['prompt']]);
        $this->saveHistory($report, $request->user()->id, 'ai_generated', $data['prompt']);

        return $this->sendOk(['definition' => $decoded, 'report' => $this->row($report->fresh(), true)]);
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
        $report->update(collect($data)->except(['permissions', 'templates'])->all() + ['version' => $report->version + 1]);
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

        return $this->sendOk($rows->map(function ($r) {
            $snapshot = json_decode($r->snapshot, true);
            // DB::table() (unlike Eloquent) returns raw "Y-m-d H:i:s" strings with no
            // timezone marker — the frontend's `new Date(...)` then misreads it as
            // local time instead of UTC. Force an explicit UTC ISO8601 string.
            $createdAt = \Carbon\Carbon::parse($r->created_at, 'UTC')->toIso8601String();

            return [
                'id'              => $r->id,
                'action'          => $r->action,
                'snapshot'        => $snapshot,
                'version_label'   => $this->versionLabel((int) ($snapshot['version'] ?? 0), $r->created_at),
                'changed_by_name' => $r->changed_by_name,
                'created_at'      => $createdAt,
            ];
        }));
    }

    // Bulk or full history wipe (reports.edit) — `ids` in the body deletes just
    // those rows, omitting it clears everything for this report.
    public function clearHistory(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'edit');
        $data = $request->validate(['ids' => 'nullable|array', 'ids.*' => 'integer']);

        $query = DB::table('report_histories')->where('report_id', $report->id);
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        }
        $deleted = $query->delete();
        $this->audit->log('report.history_cleared', Report::class, $report->id, null, ['deleted' => $deleted]);

        return $this->sendOk(['deleted' => $deleted]);
    }

    public function logs(Request $request, Report $report): JsonResponse
    {
        $this->authorizeReport($request, $report, 'view');

        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.object_type', Report::class)
            ->where('a.object_id', (string) $report->id)
            ->orderByDesc('a.created_at')
            ->limit(100)
            ->get(['a.id', 'a.action', 'a.new_values', 'a.created_at', 'u.name as user_name']);

        return $this->sendOk($rows->map(fn ($r) => [
            'id'         => $r->id,
            'event'      => $r->action,
            'properties' => json_decode($r->new_values ?? '{}', true),
            'user_name'  => $r->user_name ?? 'System',
            'created_at' => \Carbon\Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ]));
    }

    // Per-report ACL gate (FR-M6 ACL) — abort 403 if the user lacks the ability.
    // Lets Preview render the Studio's live, unsaved edits (columns, template
    // header/footer, conditional rules, etc.) instead of only what's already
    // persisted — an editor's changes should show up immediately without
    // forcing a Save first. Only mutates the in-memory model (never saved);
    // only applied for users who could actually edit this report, so a
    // run-only viewer can't use it to see arbitrary unsaved content.
    private function applyDraftDefinition(Request $request, Report $report): void
    {
        if (! $request->has('definition')) {
            return;
        }
        if (! $report->allows($request->user(), 'edit')) {
            return;
        }
        $report->definition = (array) $request->input('definition');
    }

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
            'tags'            => 'nullable|array',
            'tags.*'          => 'string|max:40',
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
            'definition.columns.*.label' => 'nullable|string|max:160',
            'definition.columns.*.format' => 'nullable|string|max:40',
            'definition.columns.*.value_map' => 'nullable|array',
            'definition.columns.*.calc'  => 'nullable|string|max:500',
            'definition.columns.*.align' => ['nullable', Rule::in(['left', 'right', 'center'])],
            'definition.columns.*.hidden' => 'nullable|boolean',
            'definition.groups'      => 'nullable|array',
            'definition.aggregates'  => 'nullable|array',
            'definition.filters'     => 'nullable|array',
            'definition.sorts'       => 'nullable|array',
            'definition.params'      => 'nullable|array',
            'definition.conditional' => 'nullable|array',
            'definition.striped'     => 'nullable|boolean',
            'definition.show_row_number' => 'nullable|boolean',
            'definition.chart_type'  => ['nullable', Rule::in(['bar', 'line', 'pie'])],
            'definition.narrative'   => 'nullable|string|max:2000',
            'definition.prompt'      => 'nullable|string|max:2000',
            'definition.prompt_history'       => 'nullable|array',
            'definition.prompt_history.*.text' => 'nullable|string|max:2000',
            'definition.prompt_history.*.at'   => 'nullable|string|max:40',
            // Report-level parameters (left panel > Parameters): a toggle map for the
            // dataset's fixed params, plus report-defined custom parameters.
            'definition.fixed_parameters_enabled'          => 'nullable|array',
            'definition.require_parameter_screen'          => 'nullable|boolean',
            'definition.filter_condition'                  => 'nullable|string|max:1000',
            'definition.show_header_menu'                  => 'nullable|boolean',
            'definition.template_header_id' => 'nullable|integer|exists:templates,id',
            'definition.template_footer_id' => 'nullable|integer|exists:templates,id',
            'definition.custom_parameters'                 => 'nullable|array',
            'definition.custom_parameters.*.id'             => 'nullable|string|max:60',
            'definition.custom_parameters.*.title'          => 'required_with:definition.custom_parameters|string|max:160',
            'definition.custom_parameters.*.name'           => 'required_with:definition.custom_parameters|string|max:60|regex:/^[A-Za-z0-9_]+$/',
            'definition.custom_parameters.*.type'           => ['required_with:definition.custom_parameters', Rule::in(['text', 'dropdown', 'checkbox', 'radio', 'date', 'datetime', 'time', 'amount'])],
            'definition.custom_parameters.*.default_value'  => 'nullable',
            'definition.custom_parameters.*.min'            => 'nullable|numeric',
            'definition.custom_parameters.*.max'            => 'nullable|numeric',
            'definition.custom_parameters.*.source_type'    => ['nullable', Rule::in(['json', 'datasource'])],
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
                'version'    => $report->version,
            ]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // Version label = the date of that save + a running number that increments
    // on every save (not per day) — e.g. 20260710.0007.
    private function versionLabel(int $version, $date): string
    {
        $d = $date instanceof \Carbon\CarbonInterface ? $date : \Carbon\Carbon::parse((string) $date);

        return $d->format('Ymd') . '.' . str_pad((string) $version, 4, '0', STR_PAD_LEFT);
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
            'tags'        => $r->tags ?? [],
            'version'     => $r->version,
            'version_label' => $this->versionLabel($r->version, $r->updated_at ?? now()),
            'layout'      => $r->layout,
            'printout_size'   => $r->printout_size,
            'printout_width'  => $r->printout_width,
            'printout_height' => $r->printout_height,
            'output_formats'  => $r->output_formats ?? [],
            'locked'      => (bool) $r->locked,
            'project'     => $r->project ? ['id' => $r->project->id, 'code' => $r->project->code, 'name' => $r->project->name] : null,
            'dataset'     => $r->dataset ? ['id' => $r->dataset->id, 'name' => $r->dataset->name, 'data_source_id' => $r->dataset->data_source_id] : null,
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
