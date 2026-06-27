<?php

namespace App\Services\Reporting;

use App\Models\Report;

/**
 * M6 (FR-M6.2/M6.3) — deterministic render engine. Turns a report definition +
 * dataset rows into print-stable Tailwind HTML. v1 supports: table/list,
 * grouped (group-above + subtotals/grand total), and KPI/summary cards.
 * Output is intentionally pure HTML (no JS) so it is reproducible for PDF (M10).
 */
class ReportRenderer
{
    /** @param array{columns:array,rows:array} $data */
    public function render(Report $report, array $data): string
    {
        $def = $report->definition ?? [];
        $type = $def['type'] ?? $report->type ?? 'table';
        $rows = $data['rows'] ?? [];
        $columns = $this->columns($def, $data['columns'] ?? []);

        $body = match ($type) {
            'grouped' => $this->grouped($def, $columns, $rows),
            'kpi'     => $this->kpi($def, $rows),
            default   => $this->table($columns, $rows, $def),
        };

        return $this->frame($report, $body);
    }

    // --- column resolution ---

    /** Use definition columns if present, else every dataset column. */
    private function columns(array $def, array $datasetColumns): array
    {
        if (! empty($def['columns'])) {
            return array_map(fn ($c) => [
                'field'  => $c['field'],
                'label'  => $c['label'] ?? $this->humanize($c['field']),
                'format' => $c['format'] ?? 'text',
            ], $def['columns']);
        }

        return array_map(fn ($f) => ['field' => $f, 'label' => $this->humanize($f), 'format' => 'text'], $datasetColumns);
    }

    // --- renderers ---

    private function table(array $columns, array $rows, array $def): string
    {
        $head = implode('', array_map(
            fn ($c) => '<th class="text-left font-semibold text-slate-600 px-3 py-2 border-b border-slate-200">' . e($c['label']) . '</th>',
            $columns,
        ));

        $body = '';
        foreach ($rows as $row) {
            $tds = '';
            foreach ($columns as $c) {
                $tds .= '<td class="px-3 py-1.5 border-b border-slate-100' . $this->condClass($def, $c['field'], $row) . '">'
                    . e($this->fmt($row[$c['field']] ?? null, $c['format'])) . '</td>';
            }
            $body .= "<tr>{$tds}</tr>";
        }
        if (! $rows) {
            $body = '<tr><td class="px-3 py-4 text-slate-400" colspan="' . count($columns) . '">No data.</td></tr>';
        }

        return '<table class="w-full text-sm border-collapse"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    private function grouped(array $def, array $columns, array $rows): string
    {
        $groupField = $def['groups'][0]['field'] ?? null;
        if (! $groupField) {
            return $this->table($columns, $rows, $def);
        }
        $aggregates = $def['aggregates'] ?? [];

        // Partition rows by group value (stable order of first appearance).
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) ($row[$groupField] ?? '—')][] = $row;
        }

        $out = '';
        foreach ($groups as $value => $groupRows) {
            $out .= '<div class="mb-4">';
            $out .= '<div class="font-semibold text-slate-700 bg-slate-50 px-3 py-1.5 rounded">'
                . e($this->humanize($groupField)) . ': ' . e($value)
                . ' <span class="text-xs text-slate-400">(' . count($groupRows) . ')</span></div>';
            $out .= $this->table($columns, $groupRows, $def);
            $sub = $this->aggregateLine($aggregates, $groupRows, 'Subtotal');
            if ($sub) {
                $out .= $sub;
            }
            $out .= '</div>';
        }
        $grand = $this->aggregateLine($aggregates, $rows, 'Grand total');
        if ($grand) {
            $out .= '<div class="border-t-2 border-slate-300 pt-1">' . $grand . '</div>';
        }

        return $out;
    }

    private function kpi(array $def, array $rows): string
    {
        $aggregates = $def['aggregates'] ?? [];
        if (! $aggregates) {
            $aggregates = [['field' => '*', 'fn' => 'count', 'label' => 'Total rows']];
        }

        $cards = '';
        foreach ($aggregates as $agg) {
            $value = $this->aggregate($agg['fn'] ?? 'count', $agg['field'] ?? '*', $rows);
            $label = $agg['label'] ?? (ucfirst($agg['fn'] ?? 'count') . ' ' . $this->humanize($agg['field'] ?? ''));
            $cards .= '<div class="rounded-xl border border-slate-100 p-5">'
                . '<div class="text-sm text-slate-500">' . e($label) . '</div>'
                . '<div class="text-3xl font-bold text-airr-600 mt-1">' . e($this->fmt($value, 'number')) . '</div></div>';
        }

        return '<div class="grid grid-cols-2 md:grid-cols-3 gap-4">' . $cards . '</div>';
    }

    // --- aggregates ---

    private function aggregateLine(array $aggregates, array $rows, string $label): string
    {
        if (! $aggregates) {
            return '';
        }
        $parts = [];
        foreach ($aggregates as $agg) {
            $v = $this->aggregate($agg['fn'] ?? 'sum', $agg['field'] ?? '*', $rows);
            $parts[] = '<span class="text-slate-500">' . e($this->humanize($agg['field'] ?? '')) . ' '
                . e($agg['fn'] ?? 'sum') . ':</span> <span class="font-semibold">' . e($this->fmt($v, 'number')) . '</span>';
        }

        return '<div class="text-sm px-3 py-1.5 text-right">' . e($label) . ' — ' . implode(' · ', $parts) . '</div>';
    }

    private function aggregate(string $fn, string $field, array $rows): float|int
    {
        if ($fn === 'count') {
            return count($rows);
        }
        $nums = array_map(fn ($r) => (float) ($r[$field] ?? 0), $rows);
        return match ($fn) {
            'sum'     => array_sum($nums),
            'avg'     => $nums ? array_sum($nums) / count($nums) : 0,
            'min'     => $nums ? min($nums) : 0,
            'max'     => $nums ? max($nums) : 0,
            default   => array_sum($nums),
        };
    }

    // --- conditional formatting (FR-M6.4, simple field op value) ---

    private function condClass(array $def, string $field, array $row): string
    {
        foreach ($def['conditional'] ?? [] as $rule) {
            if (($rule['field'] ?? null) !== $field) {
                continue;
            }
            if ($this->matches($row[$field] ?? null, $rule['op'] ?? '=', $rule['value'] ?? null)) {
                $color = $rule['style']['color'] ?? null;
                return $color ? ' ' . $this->colorClass($color) : '';
            }
        }

        return '';
    }

    private function matches($actual, string $op, $expected): bool
    {
        return match ($op) {
            '=', '=='  => $actual == $expected,
            '!='       => $actual != $expected,
            '>'        => (float) $actual > (float) $expected,
            '<'        => (float) $actual < (float) $expected,
            '>='       => (float) $actual >= (float) $expected,
            '<='       => (float) $actual <= (float) $expected,
            default    => false,
        };
    }

    private function colorClass(string $color): string
    {
        return match ($color) {
            'red'    => 'text-rose-600 font-medium',
            'green'  => 'text-emerald-600 font-medium',
            'amber'  => 'text-amber-600 font-medium',
            default  => 'text-slate-700',
        };
    }

    // --- helpers ---

    private function fmt($value, string $format): string
    {
        if ($value === null) {
            return '';
        }
        return match ($format) {
            'number'   => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2), '0'), '.') : (string) $value,
            'currency' => 'RM ' . number_format((float) $value, 2),
            'percent'  => number_format((float) $value, 1) . '%',
            default    => (string) $value,
        };
    }

    private function humanize(string $field): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $field));
    }

    private function frame(Report $report, string $body): string
    {
        return '<div class="airr-report space-y-3">'
            . '<h1 class="text-lg font-bold text-slate-800">' . e($report->name) . '</h1>'
            . ($report->description ? '<p class="text-sm text-slate-500">' . e($report->description) . '</p>' : '')
            . '<div>' . $body . '</div></div>';
    }
}
