<?php

namespace App\Services\Ai;

use App\Models\DataSource;
use Illuminate\Support\Facades\DB;

// M4 (FR-M4.2) — resolve a natural-language prompt to a structured intent:
// goal, entities, and business-term→schema mappings from the semantic schema cache.
class IntentResolver
{
    /**
     * @return array{
     *   goal: string,
     *   entities: string[],
     *   term_mappings: array<string, array{table:string, column:string, definition:string}>
     * }
     */
    public function resolve(string $prompt, ?DataSource $dataSource = null): array
    {
        $termMappings = [];

        if ($dataSource) {
            $termMappings = $this->lookupTerms($prompt, $dataSource->id);
        }

        return [
            'goal'          => $prompt,
            'entities'      => $this->extractEntities($prompt),
            'term_mappings' => $termMappings,
        ];
    }

    /**
     * Query the semantic_schema_cache table for terms that appear in the prompt.
     *
     * @return array<string, array{table:string, column:string, definition:string}>
     */
    private function lookupTerms(string $prompt, int $dataSourceId): array
    {
        $lower = strtolower($prompt);

        // Pull all terms for this data source; filter in PHP to avoid ILIKE per-term round trips.
        $rows = DB::table('semantic_schema_cache')
            ->where('data_source_id', $dataSourceId)
            ->select('term', 'table_name', 'column_name', 'definition')
            ->get();

        $mappings = [];
        foreach ($rows as $row) {
            if (str_contains($lower, strtolower($row->term))) {
                $mappings[$row->term] = [
                    'table'      => $row->table_name,
                    'column'     => $row->column_name,
                    'definition' => $row->definition ?? '',
                ];
            }
        }

        return $mappings;
    }

    /** Naive entity extraction — picks capitalised tokens as candidate entities. */
    private function extractEntities(string $prompt): array
    {
        preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/', $prompt, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }
}
