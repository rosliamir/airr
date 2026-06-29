<?php

namespace App\Services\Ai;

// M4 (FR-M4.7) — merge RAG text passages with numeric query results into a
// single fused block that the WriterAgent can compose into the final report.
class HybridContextFuser
{
    /**
     * @param  array<string,mixed>  $context  pipeline context bag
     * @return array{narrative:string, table_md:string, fused_block:string}
     */
    public function fuse(array $context): array
    {
        $narrative = $this->buildNarrative($context['rag_chunks'] ?? []);
        $tableMd   = $this->buildMarkdownTable(
            $context['query_columns'] ?? [],
            $context['query_rows']    ?? []
        );

        $fused = implode("\n\n", array_filter([$narrative, $tableMd]));

        return [
            'narrative'   => $narrative,
            'table_md'    => $tableMd,
            'fused_block' => $fused,
        ];
    }

    /** @param  array<int,array{heading?:string,content:string,score?:float}>  $chunks */
    private function buildNarrative(array $chunks): string
    {
        if (empty($chunks)) {
            return '';
        }

        $parts = [];
        foreach (array_slice($chunks, 0, 6) as $chunk) {
            $heading = $chunk['heading'] ?? null;
            $content = trim($chunk['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $parts[] = $heading ? "**{$heading}**\n{$content}" : $content;
        }

        return implode("\n\n", $parts);
    }

    /** @param  string[]  $columns  @param  array<int,array<string,mixed>>  $rows */
    private function buildMarkdownTable(array $columns, array $rows): string
    {
        if (empty($columns) || empty($rows)) {
            return '';
        }

        $header    = '| ' . implode(' | ', $columns) . ' |';
        $separator = '| ' . implode(' | ', array_fill(0, count($columns), '---')) . ' |';

        $lines = [$header, $separator];
        foreach ($rows as $row) {
            $cells = array_map(
                fn ($col) => str_replace('|', '\\|', (string) ($row[$col] ?? '')),
                $columns
            );
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        return implode("\n", $lines);
    }
}
