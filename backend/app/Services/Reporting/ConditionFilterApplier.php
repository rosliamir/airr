<?php

namespace App\Services\Reporting;

use App\Models\Report;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ModelResolver;
use App\Services\ConstantResolver;
use Illuminate\Support\Facades\Auth;

// The report's Property panel may carry a single plain-language
// "filter_condition" (e.g. "only show rows where jumlah bayaran is above
// {{GLOBAL:SST}}") instead of a hand-written SQL WHERE — resolve any
// constant tokens in it, then ask the AI which of the already-fetched rows
// satisfy it. Best-effort: if there's no condition set, or the AI call
// fails or returns something unusable, the original rows are returned
// unfiltered rather than failing the whole run. Shared by
// ReportController::run()/exportFile() and SavedViewController::run(), so a
// saved view's own filter behaves identically to running the report directly.
class ConditionFilterApplier
{
    public function __construct(protected ConstantResolver $constants) {}

    public function apply(Report $report, array $rows, ModelResolver $resolver, AiProvider $ai, array $customParams = []): array
    {
        if (! $rows) {
            return $rows;
        }
        $def = $report->definition ?? [];
        $condition = trim((string) ($def['filter_condition'] ?? ''));
        if ($condition === '') {
            return $rows;
        }
        $condition = $this->constants->resolve($condition, $report->project_id ?? null, Auth::user(), null, $customParams, $report->name);

        try {
            $model = $resolver->model('generation');
            $prompt = "Rows (JSON array, 0-indexed):\n" . json_encode(array_values($rows), JSON_PARTIAL_OUTPUT_ON_ERROR)
                . "\n\nCondition (a row must satisfy this to be kept):\n{$condition}"
                . "\n\nReturn ONLY a JSON array of the 0-based indices of the rows to KEEP — nothing else, no markdown fences, no explanation.";
            $raw = $ai->generate($model, $prompt, [
                'system' => 'You filter tabular data rows against plain-language conditions. '
                    . 'Dates/times in the condition and in the row values are very often written in different '
                    . 'formats (dd/mm/yyyy, mm/dd/yyyy, yyyy-mm-dd, with or without time) — always compare them '
                    . 'by their actual calendar date/time meaning, never by exact string equality. The same '
                    . 'applies to numbers with different formatting (thousands separators, trailing zeros, '
                    . 'currency symbols) — compare by numeric value. Respond with only a JSON array of integer indices.',
                'temperature' => 0,
            ]);
            $indices = $this->extractJsonArrayOfInts($raw);
            if ($indices === null) {
                return $rows;
            }

            return array_values(array_intersect_key($rows, array_flip($indices)));
        } catch (\Throwable) {
            return $rows;
        }
    }

    private function extractJsonArrayOfInts(string $raw): ?array
    {
        $trimmed = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }
        if (! preg_match('/\[.*\]/s', $trimmed, $m)) {
            return null;
        }
        $decoded = json_decode($m[0], true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        return array_values(array_filter(array_map(fn ($v) => is_numeric($v) ? (int) $v : null, $decoded), fn ($v) => $v !== null));
    }
}
