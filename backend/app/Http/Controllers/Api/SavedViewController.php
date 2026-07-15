<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Report;
use App\Models\SavedView;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;
use App\Services\AuditService;
use App\Services\ConnectorService;
use App\Services\ConstantResolver;
use App\Services\Reporting\DefinitionPromptEditor;
use App\Services\Reporting\ReportRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// A viewer's personal, prompt-editable snapshot of a report's definition —
// created from the report's own view (/reports/{id}/view), not the editor.
// Deliberately separate from Report/Template: creating or editing one only
// needs reports.run (view-level) access, never touches the source report,
// and is only visible to the user who created it (own-URL, reopenable).
class SavedViewController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ConnectorService $connector,
        protected ReportRenderer $renderer,
        protected ConstantResolver $constants,
        protected AuditService $audit,
    ) {}

    // Create a saved view from the report's current (or caller-supplied)
    // definition — the starting point for the viewer's own copy.
    public function store(Request $request, Report $report): JsonResponse
    {
        $this->authorizeRun($request, $report);
        $data = $request->validate([
            'name'       => 'nullable|string|max:160',
            'definition' => 'nullable|array',
        ]);

        $view = SavedView::create([
            'report_id'  => $report->id,
            'user_id'    => $request->user()->id,
            'name'       => $data['name'] ?? ($report->name . ' — my view'),
            'definition' => $data['definition'] ?? ($report->definition ?? []),
        ]);
        $this->audit->log('saved_view.created', SavedView::class, $view->id, null, ['report_id' => $report->id]);

        return $this->sendCreated($this->row($view));
    }

    public function show(Request $request, SavedView $savedView): JsonResponse
    {
        $this->authorizeOwner($request, $savedView);

        return $this->sendOk($this->row($savedView->load('report:id,name,description')));
    }

    public function update(Request $request, SavedView $savedView): JsonResponse
    {
        $this->authorizeOwner($request, $savedView);
        $data = $request->validate([
            'name'       => 'nullable|string|max:160',
            'definition' => 'required|array',
        ]);
        $savedView->update([
            'name'       => $data['name'] ?? $savedView->name,
            'definition' => $data['definition'],
        ]);
        $this->audit->log('saved_view.updated', SavedView::class, $savedView->id);

        return $this->sendOk($this->row($savedView->fresh()));
    }

    public function destroy(Request $request, SavedView $savedView): JsonResponse
    {
        $this->authorizeOwner($request, $savedView);
        $savedView->delete();
        $this->audit->log('saved_view.deleted', SavedView::class, $savedView->id);

        return $this->sendNoContent();
    }

    public function index(Request $request, Report $report): JsonResponse
    {
        $this->authorizeRun($request, $report);
        $views = SavedView::where('report_id', $report->id)->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')->get();

        return $this->sendOk($views->map(fn ($v) => $this->row($v))->all());
    }

    // Runs the saved view's own definition against the SOURCE report's
    // dataset — same rendering pipeline as ReportController::run(), just
    // fed the saved view's definition instead of the report's persisted one.
    public function run(Request $request, SavedView $savedView): JsonResponse
    {
        $this->authorizeOwner($request, $savedView);
        $params = (array) $request->input('params', []);
        $report = $savedView->report;
        $report->definition = $savedView->definition; // in-memory only, never saved back to the report

        $data = ['columns' => [], 'rows' => []];
        if ($report->dataset) {
            $isFiltered = false;
            foreach ($params as $v) {
                if ($v !== null && $v !== '') {
                    $isFiltered = true;
                    break;
                }
            }
            try {
                $result = $this->connector->run($report->dataset, $params, 1, 20, $isFiltered);
            } catch (\Throwable $e) {
                return $this->sendError(503, 'DATASOURCE_UNAVAILABLE', 'Could not reach the data source: ' . $e->getMessage());
            }
            $data = ['columns' => $result['columns'] ?? [], 'rows' => $result['rows'] ?? []];
        }

        $html = $this->renderer->render($report, $data);

        return $this->sendOk(['html' => $html, 'row_count' => count($data['rows'])]);
    }

    public function generateFromPrompt(Request $request, SavedView $savedView, ModelResolver $resolver, AiProvider $ai, DefinitionPromptEditor $editor): JsonResponse
    {
        $this->authorizeOwner($request, $savedView);
        $data = $request->validate([
            'prompt' => 'required|string|max:2000',
            'file'   => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,pdf,txt,md,csv',
        ]);

        $sampleRows = null;
        $report = $savedView->report;
        if ($report && $report->dataset) {
            try {
                $sampleRows = $this->connector->run($report->dataset, [], 1, 30)['rows'] ?? null;
            } catch (\Throwable) {
                $sampleRows = null;
            }
        }

        try {
            $decoded = $editor->apply(
                (array) ($savedView->definition ?? []),
                $data['prompt'],
                $request->file('file'),
                $resolver->model('generation'),
                $ai,
                $sampleRows,
            );
        } catch (\RuntimeException) {
            return $this->sendError(422, 'AI_RESPONSE_INVALID', 'The AI did not return valid JSON. Try rephrasing the instruction.');
        } catch (\Throwable $e) {
            return $this->sendError(503, 'AI_UNAVAILABLE', 'Could not reach the AI provider: ' . $e->getMessage());
        }

        $savedView->update(['definition' => $decoded, 'prompt' => $data['prompt']]);
        $this->audit->log('saved_view.ai_generated', SavedView::class, $savedView->id, null, ['prompt' => $data['prompt']]);

        return $this->sendOk(['definition' => $decoded, 'saved_view' => $this->row($savedView->fresh())]);
    }

    private function authorizeRun(Request $request, Report $report): void
    {
        if (! $report->allows($request->user(), 'run')) {
            abort(response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You do not have run access to this report.'],
            ], 403));
        }
    }

    private function authorizeOwner(Request $request, SavedView $savedView): void
    {
        if ($savedView->user_id !== $request->user()->id) {
            abort(response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'This saved view belongs to another user.'],
            ], 403));
        }
    }

    private function row(SavedView $v): array
    {
        return [
            'id' => $v->id, 'report_id' => $v->report_id, 'name' => $v->name,
            'definition' => $v->definition, 'prompt' => $v->prompt,
            // The real, authoritative history lives inside definition.prompt_history —
            // DefinitionPromptEditor::apply() already appends to it on every prompt, the
            // same way the report editor's own prompt history works. The separate
            // saved_views.prompt_history DB column is unused; reading it here (as this
            // used to) meant history always came back empty on reload.
            'prompt_history' => ($v->definition['prompt_history'] ?? []),
            'report' => $v->relationLoaded('report') && $v->report ? ['id' => $v->report->id, 'name' => $v->report->name] : null,
            'created_at' => $v->created_at, 'updated_at' => $v->updated_at,
        ];
    }
}
