<?php

namespace App\Services\Ai;

use App\Exceptions\SqlGuardrailException;
use App\Models\KnowledgeBase;
use App\Models\OrchestrationRun;
use App\Models\OrchestrationStep;
use App\Services\AuditService;
use App\Services\Ai\Agents\AuditorAgent;
use App\Services\Ai\Agents\ComplianceAgent;
use App\Services\Ai\Agents\DataAnalystAgent;
use App\Services\Ai\Agents\RetrieverAgent;
use App\Services\Ai\Agents\WriterAgent;
use Throwable;

// M4 — coordinates the Retriever→DataAnalyst→Auditor→Writer pipeline.
// Called from RunOrchestrationPipeline Job; updates DB state throughout.
class Orchestrator
{
    public function __construct(
        private readonly RetrieverAgent   $retriever,
        private readonly DataAnalystAgent $dataAnalyst,
        private readonly AuditorAgent     $auditor,
        private readonly WriterAgent      $writer,
        private readonly ComplianceAgent  $compliance,
        private readonly IntentResolver   $intentResolver,
        private readonly AuditService     $audit,
    ) {}

    /**
     * Run the full pipeline for an existing OrchestrationRun.
     * Updates run and steps in DB throughout execution.
     */
    public function execute(OrchestrationRun $run): OrchestrationRun
    {
        $startedAt = hrtime(true);

        $run->update(['status' => OrchestrationRun::STATUS_RUNNING]);

        $context = $this->buildInitialContext($run);

        $agents   = [$this->retriever, $this->dataAnalyst, $this->auditor, $this->writer];
        $sequence = 1;

        foreach ($agents as $agent) {
            $stepStart = hrtime(true);
            $step = OrchestrationStep::create([
                'orchestration_run_id' => $run->id,
                'agent'                => $agent->name(),
                'sequence'             => $sequence++,
                'status'               => 'running',
                'input'                => $this->safeSnapshot($context),
                'model_used'           => null,
            ]);

            try {
                $context = $agent->run($context);

                $step->update([
                    'status'     => 'complete',
                    'output'     => $this->agentOutput($agent->name(), $context),
                    'duration_ms'=> $this->ms($stepStart),
                    'model_used' => $this->modelUsed($agent->name(), $context),
                ]);
            } catch (SqlGuardrailException $e) {
                $step->update(['status' => 'failed', 'error' => $e->getMessage(), 'duration_ms' => $this->ms($stepStart)]);
                $run->update(['status' => OrchestrationRun::STATUS_FAILED, 'error' => 'SQL guardrail: ' . $e->getMessage()]);
                return $run->refresh();
            } catch (Throwable $e) {
                $step->update(['status' => 'failed', 'error' => $e->getMessage(), 'duration_ms' => $this->ms($stepStart)]);
                $run->update(['status' => OrchestrationRun::STATUS_FAILED, 'error' => $e->getMessage()]);
                return $run->refresh();
            }

            // After auditor step — check verdict.
            if ($agent->name() === 'auditor' && ($context['audit_verdict'] ?? 'pass') === 'reject') {
                $run->update([
                    'status' => OrchestrationRun::STATUS_REJECTED,
                    'error'  => 'Auditor rejected: ' . ($context['audit_notes'] ?? ''),
                ]);
                return $run->refresh();
            }
        }

        $run->update([
            'status'            => OrchestrationRun::STATUS_COMPLETE,
            'output_html'       => $context['output_html'] ?? null,
            'executive_summary' => $context['executive_summary'] ?? null,
            'intent'            => $context['intent'] ?? null,
            'context_fusion'    => $context['context_fusion'] ?? null,
            'total_duration_ms' => $this->ms($startedAt),
        ]);

        $this->audit->log('orchestration.complete', 'orchestration_run', $run->id);

        return $run->refresh();
    }

    /**
     * Run only the compliance check on a completed run (FR-M4.10).
     * Called explicitly on publish — not part of the generation pipeline.
     */
    public function checkCompliance(OrchestrationRun $run): OrchestrationRun
    {
        if ($run->status !== OrchestrationRun::STATUS_COMPLETE) {
            return $run;
        }

        $context = [
            'output_html'       => $run->output_html,
            'executive_summary' => $run->executive_summary,
            'project'           => $run->project,
        ];

        $startedAt = hrtime(true);
        $step = OrchestrationStep::create([
            'orchestration_run_id' => $run->id,
            'agent'                => 'compliance',
            'sequence'             => $run->steps()->count() + 1,
            'status'               => 'running',
        ]);

        try {
            $context = $this->compliance->run($context);

            $step->update([
                'status'     => 'complete',
                'output'     => ['passed' => $context['compliance_passed'], 'notes' => $context['compliance_notes']],
                'duration_ms'=> $this->ms($startedAt),
            ]);

            $run->update([
                'compliance_passed' => $context['compliance_passed'],
                'compliance_notes'  => $context['compliance_notes'],
            ]);

            $this->audit->log('orchestration.compliance_checked', 'orchestration_run', $run->id);
        } catch (Throwable $e) {
            $step->update(['status' => 'failed', 'error' => $e->getMessage(), 'duration_ms' => $this->ms($startedAt)]);
        }

        return $run->refresh();
    }

    private function buildInitialContext(OrchestrationRun $run): array
    {
        $kbIds = KnowledgeBase::when($run->project_id, fn ($q) => $q->where('project_id', $run->project_id))
            ->pluck('id')
            ->toArray();

        $intent = $this->intentResolver->resolve($run->prompt, $run->dataSource);

        return [
            'prompt'          => $run->prompt,
            'project'         => $run->project,
            'data_source'     => $run->dataSource,
            'schema_snapshot' => $run->dataSource?->schema_cache ?? [],
            'kb_ids'          => $kbIds,
            'intent'          => $intent,
        ];
    }

    private function safeSnapshot(array $context): array
    {
        // Exclude large payloads from the input snapshot to keep DB rows lean.
        $exclude = ['output_html', 'executive_summary'];
        return array_diff_key($context, array_flip($exclude));
    }

    private function agentOutput(string $agentName, array $context): array
    {
        return match ($agentName) {
            'retriever'    => ['rag_chunks_count' => count($context['rag_chunks'] ?? [])],
            'data_analyst' => [
                'sql_validated'  => $context['sql_validated'] ?? null,
                'query_count'    => $context['query_count'] ?? 0,
                'query_columns'  => $context['query_columns'] ?? [],
            ],
            'auditor'      => [
                'verdict'    => $context['audit_verdict']    ?? null,
                'confidence' => $context['audit_confidence'] ?? null,
                'notes'      => $context['audit_notes']      ?? null,
            ],
            'writer'       => ['output_html_length' => strlen($context['output_html'] ?? '')],
            default        => [],
        };
    }

    private function modelUsed(string $agentName, array $context): ?string
    {
        return match ($agentName) {
            'retriever' => $context['retriever_model'] ?? null,
            default     => null,
        };
    }

    private function ms(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1_000_000);
    }
}
