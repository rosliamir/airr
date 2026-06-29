<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrchestrateRequest;
use App\Http\Traits\ApiResponse;
use App\Jobs\RunOrchestrationPipeline;
use App\Models\OrchestrationRun;
use App\Models\Report;
use App\Services\AuditService;
use App\Services\Ai\Orchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// M4 (FR-M4.1–FR-M4.11) — AI orchestration API.
class OrchestrationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly AuditService $audit,
    ) {}

    /**
     * POST /api/orchestration
     * Submit a new generation prompt; dispatches the pipeline job.
     * Returns 202 Accepted with run id and a poll URL.
     */
    public function store(OrchestrateRequest $request): JsonResponse
    {
        $run = OrchestrationRun::create([
            'project_id'     => $request->input('project_id'),
            'data_source_id' => $request->input('data_source_id'),
            'created_by'     => $request->user()->id,
            'prompt'         => $request->input('prompt'),
            'status'         => OrchestrationRun::STATUS_QUEUED,
        ]);

        dispatch(new RunOrchestrationPipeline($run))->onQueue(config('queue.ai_queue', 'ai'));

        $this->audit->log('orchestration.submitted', 'orchestration_run', $run->id);

        return $this->sendCreated($this->formatRun($run));
    }

    /**
     * GET /api/orchestration
     * Paginated list of runs for the authenticated user (project-scoped optional).
     */
    public function index(Request $request): JsonResponse
    {
        $query = OrchestrationRun::with('steps')
            ->where('created_by', $request->user()->id)
            ->latest();

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        $runs = $query->paginate(20);

        return $this->sendOk($runs);
    }

    /**
     * GET /api/orchestration/{run}
     * Return run detail with steps. User must own the run.
     */
    public function show(Request $request, OrchestrationRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);

        return $this->sendOk($this->formatRun($run->load('steps')));
    }

    /**
     * POST /api/orchestration/{run}/compliance
     * Trigger the compliance check on a completed run (pre-publish gate).
     */
    public function checkCompliance(Request $request, OrchestrationRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);

        if ($run->status !== OrchestrationRun::STATUS_COMPLETE) {
            return $this->sendError(409, 'run_not_complete', 'Run must be complete before compliance check.');
        }

        $run = $this->orchestrator->checkCompliance($run);

        return $this->sendOk([
            'compliance_passed' => $run->compliance_passed,
            'compliance_notes'  => $run->compliance_notes,
        ]);
    }

    /**
     * POST /api/orchestration/{run}/promote
     * Promote a completed + compliant run to a named Report record.
     */
    public function promote(Request $request, OrchestrationRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);

        if ($run->status !== OrchestrationRun::STATUS_COMPLETE) {
            return $this->sendError(409, 'run_not_complete', 'Run must be complete before promotion.');
        }

        if ($run->compliance_passed === false) {
            return $this->sendError(422, 'compliance_failed', 'Compliance check failed — cannot promote.');
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $report = Report::create([
            'project_id'  => $run->project_id,
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'type'        => 'document',
            'status'      => Report::STATUS_PUBLISHED,
            'created_by'  => $request->user()->id,
            'definition'  => [
                'source'           => 'orchestration',
                'run_id'           => $run->id,
                'output_html'      => $run->output_html,
                'executive_summary'=> $run->executive_summary,
            ],
        ]);

        $run->update(['report_id' => $report->id]);

        $this->audit->log('orchestration.promoted', 'orchestration_run', $run->id, null, ['report_id' => $report->id]);

        return $this->sendCreated(['report_id' => $report->id, 'report' => $report]);
    }

    private function authorizeRun(Request $request, OrchestrationRun $run): void
    {
        if ($run->created_by !== $request->user()->id) {
            abort(403, 'Access denied.');
        }
    }

    private function formatRun(OrchestrationRun $run): array
    {
        return [
            'id'                => $run->id,
            'status'            => $run->status,
            'prompt'            => $run->prompt,
            'output_html'       => $run->output_html,
            'executive_summary' => $run->executive_summary,
            'compliance_passed' => $run->compliance_passed,
            'compliance_notes'  => $run->compliance_notes,
            'error'             => $run->error,
            'total_duration_ms' => $run->total_duration_ms,
            'created_at'        => $run->created_at,
            'steps'             => $run->relationLoaded('steps')
                ? $run->steps->map(fn ($s) => [
                    'id'         => $s->id,
                    'agent'      => $s->agent,
                    'sequence'   => $s->sequence,
                    'status'     => $s->status,
                    'model_used' => $s->model_used,
                    'duration_ms'=> $s->duration_ms,
                    'output'     => $s->output,
                    'error'      => $s->error,
                ])->values()->toArray()
                : null,
        ];
    }
}
