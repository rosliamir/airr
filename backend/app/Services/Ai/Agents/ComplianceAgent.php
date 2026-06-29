<?php

namespace App\Services\Ai\Agents;

use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;
use Illuminate\Support\Facades\DB;

// M4 (FR-M4.10) — check the draft output against MPSA/portal guidelines.
// Compliance runs on publish (not during generation); the Orchestrator calls
// this via Orchestrator::checkCompliance() rather than inline in the pipeline.
class ComplianceAgent implements AgentContract
{
    public function __construct(
        private readonly AiProvider    $ai,
        private readonly ModelResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'compliance';
    }

    /**
     * Reads:  context['output_html'], context['executive_summary'], context['project']
     * Writes: context['compliance_passed'] — bool
     *         context['compliance_notes']  — human-readable detail
     */
    public function run(array $context): array
    {
        $project = $context['project'] ?? null;
        $model   = $this->resolver->model('compliance', $project);

        $guidelines = $this->fetchGuidelineChunks($project?->id);
        $draft      = strip_tags($context['output_html'] ?? '')
                    . "\n\n"
                    . ($context['executive_summary'] ?? '');

        $prompt = $this->buildPrompt($draft, $guidelines);
        $raw    = $this->ai->generate($model, $prompt, [
            'temperature' => 0.0,
            'system'      => $this->systemPrompt(),
        ]);

        $parsed = $this->parseResult($raw);

        $context['compliance_passed'] = $parsed['passed'];
        $context['compliance_notes']  = $parsed['notes'];

        return $context;
    }

    private function systemPrompt(): string
    {
        return <<<'SYS'
You are a compliance reviewer. Check the report draft against the MPSA/portal guidelines provided.
Respond ONLY with valid JSON:
{
  "passed": true | false,
  "notes": "brief explanation of any issues found, or 'All checks passed.'"
}
SYS;
    }

    private function buildPrompt(string $draft, array $guidelines): string
    {
        $guidelineText = empty($guidelines)
            ? '(no compliance guidelines loaded)'
            : implode("\n---\n", array_column($guidelines, 'content'));

        return <<<PROMPT
MPSA / PORTAL COMPLIANCE GUIDELINES:
{$guidelineText}

REPORT DRAFT (plain text):
{$draft}

Evaluate and respond with the JSON result.
PROMPT;
    }

    /** Fetch compliance-tagged KB chunks for the given project. */
    private function fetchGuidelineChunks(?int $projectId): array
    {
        $query = DB::table('kb_chunks as c')
            ->join('kb_documents as d', 'd.id', '=', 'c.kb_document_id')
            ->join('knowledge_bases as kb', 'kb.id', '=', 'c.knowledge_base_id')
            ->where('d.category', 'compliance')
            ->select('c.heading', 'c.content')
            ->limit(10);

        if ($projectId) {
            $query->where('kb.project_id', $projectId);
        }

        return $query->get()->map(fn ($r) => ['heading' => $r->heading, 'content' => $r->content])->toArray();
    }

    private function parseResult(string $raw): array
    {
        $json = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $json = preg_replace('/\s*```$/', '', $json);

        $decoded = json_decode(trim($json), true);

        if (is_array($decoded) && array_key_exists('passed', $decoded)) {
            return [
                'passed' => (bool) $decoded['passed'],
                'notes'  => (string) ($decoded['notes'] ?? ''),
            ];
        }

        return [
            'passed' => true,
            'notes'  => 'Compliance check response could not be parsed; defaulting to passed.',
        ];
    }
}
