<?php

namespace App\Services\Reporting;

use App\Models\Report;
use App\Services\ConstantResolver;
use Illuminate\Support\Facades\Auth;

/**
 * M6 (FR-M6.2/M6.3) — deterministic render engine. Turns a report definition +
 * dataset rows into print-stable Tailwind HTML. v1 supports: table/list,
 * grouped (group-above + subtotals/grand total), and KPI/summary cards.
 * Output is intentionally pure HTML (no JS) so it is reproducible for PDF (M10).
 */
class ReportRenderer
{
    public function __construct(private ConstantResolver $constants) {}

    /** @param array{columns:array,rows:array} $data */
    public function render(Report $report, array $data): string
    {
        $def = $report->definition ?? [];
        $type = $def['type'] ?? $report->type ?? 'table';
        $rows = $data['rows'] ?? [];
        $columns = $this->resolveColumns($report, $def, $data['columns'] ?? []);

        $body = match ($type) {
            'grouped' => $this->grouped($def, $columns, $rows),
            'kpi'     => $this->kpi($def, $rows),
            default   => $this->table($columns, $rows, $def),
        };

        return $this->frame($report, $body);
    }

    // Column resolution shared by render() (HTML) and tabularData() (CSV/Excel
    // export) — same value_map/calc/{{constant}} resolution both paths, so
    // exported files always match what the on-screen preview shows.
    private function resolveColumns(Report $report, array $def, array $datasetColumns): array
    {
        $columns = $this->columns($def, $datasetColumns);
        // A calc expression may reference {{SYSTEM:KEY}}/{{GLOBAL:KEY}}/{{PROJECT:KEY}}
        // constants (e.g. "jumlah_bayaran * {{GLOBAL:SST}}") — resolve those tokens to
        // their literal DB value once per column (not per row, they're row-independent).
        $projectId = $report->project_id ?? null;
        $user = Auth::user();

        return array_map(function ($c) use ($projectId, $user) {
            if ($c['calc']) {
                $c['calc'] = $this->constants->resolve($c['calc'], $projectId, $user);
            }

            return $c;
        }, $columns);
    }

    // Structured {headers, rows} for CSV/Excel export — reuses the exact same
    // column resolution + per-cell value_map/calc/format logic as the HTML
    // preview, so an exported file always matches what's on screen.
    /** @param array{columns:array,rows:array} $data */
    public function tabularData(Report $report, array $data): array
    {
        $def = $report->definition ?? [];
        $columns = $this->resolveColumns($report, $def, $data['columns'] ?? []);

        return [
            'headers' => array_map(fn ($c) => $c['label'], $columns),
            'rows'    => array_map(
                fn ($row) => array_map(fn ($c) => $this->cellValue($c, $row), $columns),
                $data['rows'] ?? [],
            ),
        ];
    }

    // --- column resolution ---

    /** Use definition columns if present, else every dataset column. */
    private function columns(array $def, array $datasetColumns): array
    {
        if (! empty($def['columns'])) {
            return array_map(fn ($c) => [
                'field'     => $c['field'],
                'label'     => $c['label'] ?? $this->humanize($c['field']),
                'format'    => $c['format'] ?? 'text',
                'value_map' => $c['value_map'] ?? null,
                'calc'      => $c['calc'] ?? null,
                'align'     => $c['align'] ?? null,
            ], $def['columns']);
        }

        return array_map(fn ($f) => ['field' => $f, 'label' => $this->humanize($f), 'format' => 'text', 'value_map' => null, 'calc' => null, 'align' => null], $datasetColumns);
    }

    private function alignClass(array $c): string
    {
        return match ($c['align'] ?? null) {
            'right'  => ' text-right',
            'center' => ' text-center',
            default  => '',
        };
    }

    // Resolve a cell's display value: calc (computed from other fields) takes the
    // raw value's place, then value_map (categorical label lookup, e.g. status
    // 0 -> "Active") takes priority over the plain format() rendering — a mapped
    // value IS the display text, not something to further number/date-format.
    private function cellValue(array $c, array $row): string
    {
        $raw = $c['calc'] ? $this->evalExpr((string) $c['calc'], $row) : ($row[$c['field']] ?? null);

        if (is_array($c['value_map'] ?? null)) {
            $key = (string) $raw;
            if (array_key_exists($key, $c['value_map'])) {
                return (string) $c['value_map'][$key];
            }
            if (array_key_exists('*', $c['value_map'])) {
                return (string) $c['value_map']['*'];
            }
        }

        return $this->fmt($raw, $c['format']);
    }

    // Minimal safe arithmetic evaluator for "calc" columns (e.g. "qty * price" or
    // "(price - discount) * qty") — supports + - * / and parentheses over row
    // field names and numeric literals. No eval(): tokenize then recursive-descent
    // parse, so a malformed/malicious expression can only throw, never execute code.
    private function evalExpr(string $expr, array $row): float|int|null
    {
        try {
            preg_match_all('/[A-Za-z_][A-Za-z0-9_]*|\d+\.?\d*|[()+\-*\/]/', $expr, $m);
            $tokens = $m[0];
            $pos = 0;
            $peek = function () use (&$pos, $tokens) { return $tokens[$pos] ?? null; };
            $next = function () use (&$pos, $tokens) { return $tokens[$pos++] ?? null; };

            // Factor/term/expr are mutually recursive — declared first, assigned below.
            $parseExpr = null; $parseTerm = null; $parseFactor = null;
            $parseFactor = function () use (&$parseExpr, $peek, $next, $row) {
                $t = $next();
                if ($t === '(') {
                    $v = $parseExpr();
                    $next(); // consume ')'
                    return $v;
                }
                if ($t === null) {
                    throw new \RuntimeException('Unexpected end of expression');
                }
                if (is_numeric($t)) {
                    return (float) $t;
                }
                return (float) ($row[$t] ?? 0); // bare identifier = row field
            };
            $parseTerm = function () use (&$parseFactor, $peek, $next) {
                $v = $parseFactor();
                while (in_array($peek(), ['*', '/'], true)) {
                    $op = $next();
                    $rhs = $parseFactor();
                    $v = $op === '*' ? $v * $rhs : ($rhs != 0 ? $v / $rhs : 0);
                }
                return $v;
            };
            $parseExpr = function () use (&$parseTerm, $peek, $next) {
                $v = $parseTerm();
                while (in_array($peek(), ['+', '-'], true)) {
                    $op = $next();
                    $rhs = $parseTerm();
                    $v = $op === '+' ? $v + $rhs : $v - $rhs;
                }
                return $v;
            };

            $result = $parseExpr();
            $rounded = round($result, 4);

            return $rounded == (int) $rounded ? (int) $rounded : $rounded;
        } catch (\Throwable) {
            return null;
        }
    }

    // --- renderers ---

    private function table(array $columns, array $rows, array $def): string
    {
        $showRowNumber = (bool) ($def['show_row_number'] ?? false);
        $striped = (bool) ($def['striped'] ?? false);

        $numberHead = $showRowNumber ? '<th class="text-left font-semibold text-slate-600 px-3 py-2 border-b border-slate-200 w-10">#</th>' : '';
        $head = $numberHead . implode('', array_map(
            fn ($c) => '<th class="text-left font-semibold text-slate-600 px-3 py-2 border-b border-slate-200' . $this->alignClass($c) . '">' . e($c['label']) . '</th>',
            $columns,
        ));

        $body = '';
        $i = 0;
        foreach ($rows as $row) {
            $i++;
            // A matching conditional "background" rule highlights the whole row
            // (so it reaches every column, e.g. the last one) — it takes
            // precedence over the plain zebra stripe when both would apply.
            $condBg = $this->rowConditionalBg($def, $row);
            $rowClass = $condBg !== '' ? ' class="' . $condBg . '"' : ($striped && $i % 2 === 0 ? ' class="bg-slate-50"' : '');
            $numberCell = $showRowNumber ? '<td class="px-3 py-1.5 border-b border-slate-100 text-slate-400">' . $i . '</td>' : '';
            $tds = $numberCell;
            foreach ($columns as $c) {
                $tds .= '<td class="px-3 py-1.5 border-b border-slate-100' . $this->alignClass($c) . $this->condClass($def, $c['field'], $row) . '">'
                    . e($this->cellValue($c, $row)) . '</td>';
            }
            $body .= "<tr{$rowClass}>{$tds}</tr>";
        }
        if (! $rows) {
            $body = '<tr><td class="px-3 py-4 text-slate-400" colspan="' . (count($columns) + ($showRowNumber ? 1 : 0)) . '">No data.</td></tr>';
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

    // Cell-level: only text color (a row-wide background is handled by
    // rowConditionalBg() instead, below, so it reaches every column). Keeps
    // checking subsequent rules for this field until one with a color is
    // found, rather than stopping at the first match overall — otherwise an
    // earlier color-only rule could hide a later rule's color for the same
    // field.
    private function condClass(array $def, string $field, array $row): string
    {
        foreach ($def['conditional'] ?? [] as $rule) {
            if (($rule['field'] ?? null) !== $field) {
                continue;
            }
            if (! $this->matches($row[$field] ?? null, $rule['op'] ?? '=', $rule['value'] ?? null)) {
                continue;
            }
            if ($color = $rule['style']['color'] ?? null) {
                return ' ' . $this->colorClass($color);
            }
        }

        return '';
    }

    // Row-level: the first matching rule (across all fields, in definition
    // order) that specifies a background wins, so the whole <tr> is
    // highlighted rather than just the single column the rule's condition
    // references.
    private function rowConditionalBg(array $def, array $row): string
    {
        foreach ($def['conditional'] ?? [] as $rule) {
            $field = $rule['field'] ?? null;
            if (! $field || ! array_key_exists($field, $row)) {
                continue;
            }
            if (! $this->matches($row[$field] ?? null, $rule['op'] ?? '=', $rule['value'] ?? null)) {
                continue;
            }
            if ($bg = $rule['style']['background'] ?? null) {
                return $this->bgClass($bg);
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

    private function bgClass(string $color): string
    {
        return match ($color) {
            'red'    => 'bg-rose-50',
            'green'  => 'bg-emerald-50',
            'amber'  => 'bg-amber-50',
            'gray', 'grey' => 'bg-slate-100',
            default  => '',
        };
    }

    // --- helpers ---

    // Date-like formats parse the raw value once, then re-print it — if parsing
    // fails (not actually a date), fall back to the raw value untouched.
    private const DATE_FORMATS = [
        'date'          => 'Y-m-d',
        'date_dmy'      => 'd/m/Y',
        'date_mdy'      => 'm/d/Y',
        'datetime'      => 'Y-m-d H:i:s',
        'datetime_dmy'  => 'd/m/Y H:i:s',
    ];

    private function fmt($value, string $format): string
    {
        if ($value === null) {
            return '';
        }
        if (isset(self::DATE_FORMATS[$format])) {
            try {
                return \Carbon\Carbon::parse((string) $value)->format(self::DATE_FORMATS[$format]);
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        return match ($format) {
            // Trims trailing zeros/decimal point — 40.80 -> "40.8", 420.00 -> "420".
            'number'       => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2), '0'), '.') : (string) $value,
            // ALWAYS shows exactly 2 decimals with a thousands separator, e.g. "9,999.99".
            'number_fixed' => is_numeric($value) ? number_format((float) $value, 2) : (string) $value,
            'currency'     => 'RM ' . number_format((float) $value, 2),
            'percent'      => number_format((float) $value, 1) . '%',
            default        => (string) $value,
        };
    }

    private function humanize(string $field): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $field));
    }

    private function frame(Report $report, string $body): string
    {
        $def = $report->definition ?? [];
        $enabled = $def['fixed_parameters_enabled'] ?? [];
        $projectId = $report->project_id ?? null;
        $user = Auth::user();

        $headerHtml = ($enabled['template_header'] ?? true) ? $this->templateSectionHtml($def['template_header_id'] ?? null, ['header', 'page_header'], $projectId, $user) : '';
        $footerHtml = ($enabled['template_footer'] ?? true) ? $this->templateSectionHtml($def['template_footer_id'] ?? null, ['footer', 'page_footer'], $projectId, $user) : '';

        return '<div class="airr-report space-y-3">'
            . ($headerHtml !== '' ? '<div class="airr-report-header">' . $headerHtml . '</div>' : '')
            . '<h1 class="text-lg font-bold text-slate-800">' . e($report->name) . '</h1>'
            . '<div>' . $body . '</div>'
            . ($footerHtml !== '' ? '<div class="airr-report-footer">' . $footerHtml . '</div>' : '')
            . '</div>';
    }

    // Pull the chosen Template's header/footer HTML (first non-empty field of
    // the given fallback list) and resolve any {{SYSTEM:...}}/{{GLOBAL:...}}/
    // {{PROJECT:...}} constants inside it before it's embedded in the output.
    private function templateSectionHtml(?int $templateId, array $fields, ?int $projectId, $user): string
    {
        if (! $templateId) {
            return '';
        }
        $template = \App\Models\Template::find($templateId);
        if (! $template) {
            return '';
        }
        foreach ($fields as $field) {
            if (! empty($template->{$field})) {
                return $this->constants->resolve((string) $template->{$field}, $projectId, $user);
            }
        }

        return '';
    }
}
