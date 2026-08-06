<?php

namespace App\Services\Reporting;

use App\Models\Announcement;
use App\Models\Dashboard;
use App\Models\Report;
use App\Models\Task;
use App\Services\ConnectorService;

/**
 * Dashboard Authoring — deterministic render engine. Turns a dashboard
 * definition's widgets into a CSS-grid of Tailwind HTML cards, mirroring
 * ReportRenderer's pure-HTML, print-stable approach.
 */
class DashboardRenderer
{
    public function __construct(private ConnectorService $connector) {}

    public function render(Dashboard $dashboard, array $data = []): string
    {
        $widgets = $dashboard->definition['widgets'] ?? [];

        $cells = '';
        foreach ($widgets as $widget) {
            $x = (int) ($widget['x'] ?? 0);
            $y = (int) ($widget['y'] ?? 0);
            $w = max(1, (int) ($widget['w'] ?? 4));
            $h = max(1, (int) ($widget['h'] ?? 2));
            $style = "grid-column: " . ($x + 1) . " / span {$w}; grid-row: " . ($y + 1) . " / span {$h};";

            $cells .= '<div style="' . $style . '">' . $this->widget($widget) . '</div>';
        }

        return '<div style="display:grid;grid-template-columns:repeat(12,1fr);gap:1rem;">' . $cells . '</div>';
    }

    private function widget(array $widget): string
    {
        $type = $widget['type'] ?? '';
        $config = $widget['config'] ?? [];

        return match ($type) {
            'report'       => $this->reportWidget($config),
            'text'         => $this->textWidget($config),
            'task'         => $this->taskWidget($config),
            'announcement' => $this->announcementWidget($config),
            'chart'        => $this->chartWidget($config),
            'kpi'          => $this->kpiWidget($config),
            default        => '<p class="text-slate-400">Unknown widget type: ' . e((string) $type) . '</p>',
        };
    }

    private function card(string $title, string $body): string
    {
        return '<div class="bg-white rounded-lg border border-slate-100 p-4 h-full">'
            . ($title !== '' ? '<div class="font-semibold text-slate-700 mb-2">' . e($title) . '</div>' : '')
            . $body . '</div>';
    }

    private function reportWidget(array $config): string
    {
        $report = ! empty($config['report_id']) ? Report::find($config['report_id']) : null;
        if (! $report) {
            return $this->card($config['title'] ?? 'Report', '<p class="text-slate-400">Report not found.</p>');
        }

        $rows = [];
        $columns = [];
        if ($report->dataset_id && $report->dataset) {
            $result = $this->connector->run($report->dataset, $config['params'] ?? [], 1, 20);
            $columns = $result['columns'] ?? [];
            $rows = $result['rows'] ?? [];
        }

        $html = app(ReportRenderer::class)->render($report, ['columns' => $columns, 'rows' => $rows]);

        return $this->card($config['title'] ?? $report->name, $html);
    }

    private function chartWidget(array $config): string
    {
        $report = ! empty($config['source_report_id']) ? Report::find($config['source_report_id']) : null;
        if (! $report || ! $report->dataset_id || ! $report->dataset) {
            return $this->card($config['title'] ?? 'Chart', '<p class="text-slate-400">No data source selected.</p>');
        }

        $result = $this->connector->run($report->dataset, [], 1, 20);
        $columns = $result['columns'] ?? [];
        $rows = $result['rows'] ?? [];

        $synthetic = new Report(['type' => 'chart', 'definition' => [
            'type'       => 'chart',
            'chart_type' => $config['chart_type'] ?? 'bar',
            'groups'     => [['field' => $config['group_field'] ?? '']],
            'aggregates' => [['field' => $config['agg_field'] ?? '*', 'fn' => $config['agg_fn'] ?? 'count']],
        ]]);
        $html = app(ReportRenderer::class)->render($synthetic, ['columns' => $columns, 'rows' => $rows]);

        return $this->card($config['title'] ?? 'Chart', $html);
    }

    private function kpiWidget(array $config): string
    {
        $report = ! empty($config['source_report_id']) ? Report::find($config['source_report_id']) : null;
        if (! $report || ! $report->dataset_id || ! $report->dataset) {
            return $this->card($config['title'] ?? 'KPI', '<p class="text-slate-400">No data source selected.</p>');
        }

        $result = $this->connector->run($report->dataset, [], 1, 20);
        $columns = $result['columns'] ?? [];
        $rows = $result['rows'] ?? [];

        $agg = ['field' => $config['agg_field'] ?? '*', 'fn' => $config['agg_fn'] ?? 'count'];
        if (! empty($config['agg_label'])) {
            $agg['label'] = $config['agg_label'];
        }

        $synthetic = new Report(['type' => 'kpi', 'definition' => ['type' => 'kpi', 'aggregates' => [$agg]]]);
        $html = app(ReportRenderer::class)->render($synthetic, ['columns' => $columns, 'rows' => $rows]);

        return $this->card($config['title'] ?? 'KPI', $html);
    }

    private function textWidget(array $config): string
    {
        $content = nl2br(e((string) ($config['content'] ?? '')));

        return $this->card((string) ($config['title'] ?? ''), '<div class="text-sm text-slate-700">' . $content . '</div>');
    }

    private function taskWidget(array $config): string
    {
        $query = Task::query()->orderBy('due_date');
        if (! empty($config['assigned_to'])) {
            $query->where('assigned_to', $config['assigned_to']);
        }
        if (! empty($config['status'])) {
            $query->where('status', $config['status']);
        }
        $tasks = $query->limit(10)->get();

        if ($tasks->isEmpty()) {
            return $this->card((string) ($config['title'] ?? 'Tasks'), '<p class="text-slate-400">No tasks.</p>');
        }

        $items = $tasks->map(function (Task $t) {
            $badgeClass = match ($t->status) {
                'done'        => 'bg-emerald-50 text-emerald-600',
                'in_progress' => 'bg-amber-50 text-amber-600',
                default       => 'bg-slate-100 text-slate-500',
            };

            return '<li class="flex items-center justify-between gap-2 py-1 text-sm">'
                . '<span>' . e($t->title) . '</span>'
                . '<span class="flex items-center gap-2 shrink-0">'
                . ($t->due_date ? '<span class="text-xs text-slate-400">' . e($t->due_date->format('Y-m-d')) . '</span>' : '')
                . '<span class="text-xs px-2 py-0.5 rounded ' . $badgeClass . '">' . e($t->status) . '</span>'
                . '</span></li>';
        })->implode('');

        return $this->card((string) ($config['title'] ?? 'Tasks'), '<ul class="divide-y divide-slate-100">' . $items . '</ul>');
    }

    private function announcementWidget(array $config): string
    {
        $query = Announcement::active()->orderByDesc('created_at');
        if (! empty($config['audience'])) {
            $query->where('audience', $config['audience']);
        }
        $announcements = $query->limit(5)->get();

        if ($announcements->isEmpty()) {
            return $this->card((string) ($config['title'] ?? 'Announcements'), '<p class="text-slate-400">No announcements.</p>');
        }

        $items = $announcements->map(fn (Announcement $a) => '<div class="py-2">'
            . '<div class="font-semibold text-sm text-slate-700">' . e($a->title) . '</div>'
            . '<div class="text-sm text-slate-600">' . nl2br(e($a->body)) . '</div>'
            . '<div class="text-xs text-slate-400 mt-0.5">' . e($a->created_at?->format('Y-m-d H:i') ?? '') . '</div>'
            . '</div>')->implode('<hr class="border-slate-100">');

        return $this->card((string) ($config['title'] ?? 'Announcements'), $items);
    }
}
