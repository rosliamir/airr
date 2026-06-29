<?php

namespace App\Services\Ai\Agents;

use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;

// M4 (FR-M4.5) — double-check agent. Cross-verifies facts, figures, and quotes
// in the pipeline context against source data and RAG evidence.
// If the verdict is 'reject', the Orchestrator stops the pipeline.
class AuditorAgent implements AgentContract
{
    public function __construct(
        private readonly AiProvider    $ai,
        private readonly ModelResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'auditor';
    }

    /**
     * Reads:  context['intent'], context['rag_chunks'], context['query_rows'],
     *         context['query_columns'], context['sql_validated']
     * Writes: context['audit_verdict']    — 'pass' | 'reject'
     *         context['audit_notes']      — human-readable explanation
     *         context['audit_confidence'] — float 0..1
     */
    public function run(array $context): array
    {
        $project = $context['project'] ?? null;
        $model   = $this->resolver->model('audit', $project);

        $prompt = $this->buildAuditPrompt($context);

        $raw = $this->ai->generate($model, $prompt, [
            'temperature' => 0.0,
            'system'      => $this->systemPrompt(),
        ]);

        $parsed = $this->parseVerdict($raw);

        $context['audit_verdict']    = $parsed['verdict'];
        $context['audit_notes']      = $parsed['notes'];
        $context['audit_confidence'] = $parsed['confidence'];

        return $context;
    }

    private function systemPrompt(): string
    {
        return <<<'SYS'
You are an Auditor AI. Your sole task is to verify that the data-analyst output is
consistent with the provided evidence (SQL rows and RAG chunks).

Respond ONLY with valid JSON in this exact shape:
{
  "verdict": "pass" | "reject",
  "confidence": 0.0..1.0,
  "notes": "brief explanation"
}

Rules:
- "reject" if any numeric figure, entity name, or quote in the query results contradicts the RAG chunks or looks like a hallucination.
- "reject" if no SQL rows were returned but the prompt clearly expects data.
- "pass" if the data looks consistent, even if incomplete.
- Default to "pass" when evidence is ambiguous.
SYS;
    }

    private function buildAuditPrompt(array $context): string
    {
        $intent    = $context['intent']['goal'] ?? ($context['prompt'] ?? '');
        $sql       = $context['sql_validated'] ?? '(no SQL generated)';
        $columns   = $context['query_columns'] ?? [];
        $rows      = array_slice($context['query_rows'] ?? [], 0, 20);
        $chunks    = array_slice($context['rag_chunks'] ?? [], 0, 4);

        $rowsText = empty($rows)
            ? '(no rows returned)'
            : json_encode(array_values($rows), JSON_PRETTY_PRINT);

        $ragText = empty($chunks)
            ? '(no KB passages retrieved)'
            : implode("\n---\n", array_map(
                fn ($c) => ($c['heading'] ? "{$c['heading']}\n" : '') . $c['content'],
                $chunks
            ));

        return <<<PROMPT
USER INTENT:
{$intent}

SQL EXECUTED:
{$sql}

COLUMNS: {$this->csv($columns)}

QUERY RESULT ROWS (first 20):
{$rowsText}

KNOWLEDGE BASE EVIDENCE:
{$ragText}

Verify and respond with the JSON verdict.
PROMPT;
    }

    private function parseVerdict(string $raw): array
    {
        // Try strict JSON parse first.
        $json = trim($raw);

        // Strip markdown fences if present.
        $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
        $json = preg_replace('/\s*```$/', '', $json);

        $decoded = json_decode(trim($json), true);

        if (is_array($decoded) && isset($decoded['verdict'])) {
            return [
                'verdict'    => in_array($decoded['verdict'], ['pass', 'reject']) ? $decoded['verdict'] : 'pass',
                'confidence' => (float) ($decoded['confidence'] ?? 0.8),
                'notes'      => (string) ($decoded['notes'] ?? ''),
            ];
        }

        // Fallback: if we cannot parse, default to pass with a warning note.
        return [
            'verdict'    => 'pass',
            'confidence' => 0.5,
            'notes'      => 'Auditor response could not be parsed; defaulting to pass. Raw: ' . substr($raw, 0, 200),
        ];
    }

    private function csv(array $arr): string
    {
        return implode(', ', $arr);
    }
}
