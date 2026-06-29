<?php

namespace App\Services\Ai\Agents;

use App\Services\Ai\AiProvider;
use App\Services\Ai\HybridContextFuser;
use App\Services\Ai\ModelResolver;

// M4 (FR-M4.6 + FR-M4.8) — compose the final HTML/Tailwind report and
// an executive summary from the fused context.
class WriterAgent implements AgentContract
{
    public function __construct(
        private readonly AiProvider        $ai,
        private readonly ModelResolver     $resolver,
        private readonly HybridContextFuser $fuser,
    ) {}

    public function name(): string
    {
        return 'writer';
    }

    /**
     * Reads:  context['intent'], context['rag_chunks'], context['query_rows'],
     *         context['query_columns'], context['audit_notes'], context['project']
     * Writes: context['output_html']       — FR-M4.6 final HTML/Tailwind
     *         context['executive_summary'] — FR-M4.8 short narration
     *         context['context_fusion']    — fused block payload (FR-M4.7)
     */
    public function run(array $context): array
    {
        $project = $context['project'] ?? null;
        $model   = $this->resolver->model('generation', $project);

        // FR-M4.7 — fuse RAG text + numeric data into a merged block.
        $fused  = $this->fuser->fuse($context);
        $context['context_fusion'] = $fused;

        $intent = $context['intent']['goal'] ?? ($context['prompt'] ?? '');

        // FR-M4.6 — compose HTML report.
        $htmlPrompt = $this->buildHtmlPrompt($intent, $fused);
        $html = $this->ai->generate($model, $htmlPrompt, [
            'temperature' => 0.3,
            'system'      => $this->htmlSystemPrompt(),
        ]);

        $context['output_html'] = $this->extractHtml($html);

        // FR-M4.8 — executive summary (separate, shorter call).
        $summaryPrompt = $this->buildSummaryPrompt($intent, $fused);
        $summary = $this->ai->generate($model, $summaryPrompt, [
            'temperature' => 0.4,
            'system'      => 'You are a business writer. Produce a concise executive summary in 2–4 sentences. Plain text only, no markdown.',
        ]);

        $context['executive_summary'] = trim($summary);

        return $context;
    }

    private function htmlSystemPrompt(): string
    {
        return <<<'SYS'
You are a report designer. Produce a valid HTML fragment (no <html>/<head>/<body> tags)
styled with Tailwind CSS utility classes only.

Requirements:
- Start with an <h1> or <h2> report title derived from the intent.
- Include a data table if numeric rows are present (use <table class="...">).
- Include narrative paragraphs from the knowledge base passages.
- Use crimson/rose Tailwind colours (rose-600, rose-700) for headings and table headers.
- Output ONLY valid HTML — no markdown, no explanation, no fences.
SYS;
    }

    private function buildHtmlPrompt(string $intent, array $fused): string
    {
        return <<<PROMPT
USER INTENT: {$intent}

FUSED CONTEXT (narrative + data table):
{$fused['fused_block']}

Compose the HTML report.
PROMPT;
    }

    private function buildSummaryPrompt(string $intent, array $fused): string
    {
        return <<<PROMPT
USER INTENT: {$intent}

DATA:
{$fused['table_md']}

KNOWLEDGE BASE PASSAGES:
{$fused['narrative']}

Write the executive summary.
PROMPT;
    }

    /** Strip any leading/trailing markdown fences from the LLM output. */
    private function extractHtml(string $raw): string
    {
        $html = preg_replace('/^```(?:html)?\s*/i', '', trim($raw));
        $html = preg_replace('/\s*```$/', '', $html);
        return trim($html);
    }
}
