<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\DataSource;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// M6 (FR-M6.1/M6.2) — report definition CRUD + render engine.
class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_list_report(): void
    {
        $this->actingWithPermissions(['reports.create', 'reports.view']);

        $this->postJson('/api/reports', [
            'name' => 'Sales', 'type' => 'table',
            'definition' => ['type' => 'table', 'columns' => [['field' => 'region', 'label' => 'Region']]],
        ])->assertCreated()->assertJsonPath('data.name', 'Sales');

        $this->getJson('/api/reports')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_create_requires_permission(): void
    {
        $this->actingWithPermissions(['reports.view']); // no reports.create
        $this->postJson('/api/reports', ['name' => 'X'])->assertForbidden();
    }

    public function test_validates_report_type(): void
    {
        $this->actingWithPermissions(['reports.create']);
        $this->postJson('/api/reports', ['name' => 'X', 'type' => 'banana'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['type']]]);
    }

    private function fileReport(array $definition, string $csv): Report
    {
        Storage::fake('local');
        Storage::disk('local')->put('data_files/sales.csv', $csv);
        $ds = DataSource::create([
            'name' => 'CSV', 'type' => 'csv',
            'config' => ['file_path' => 'data_files/sales.csv', 'original_name' => 'sales.csv', 'options' => ['has_header' => true]],
        ]);
        $dataset = Dataset::create(['data_source_id' => $ds->id, 'name' => 'sales']);

        return Report::create([
            'name' => 'Report', 'type' => $definition['type'], 'dataset_id' => $dataset->id, 'definition' => $definition,
        ]);
    }

    public function test_runs_table_report_to_html(): void // FR-M6.2
    {
        $this->actingWithPermissions(['reports.run']);
        $report = $this->fileReport(
            ['type' => 'table', 'columns' => [['field' => 'region', 'label' => 'Region'], ['field' => 'amount', 'label' => 'Amount', 'format' => 'number']]],
            "region,amount\nNorth,100\nSouth,200\n",
        );

        $res = $this->postJson("/api/reports/{$report->id}/run")->assertOk();
        $this->assertSame(2, $res->json('data.row_count'));
        $html = $res->json('data.html');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('North', $html);
        $this->assertStringContainsString('Amount', $html);
    }

    public function test_runs_grouped_report_with_subtotal(): void // FR-M6.3
    {
        $this->actingWithPermissions(['reports.run']);
        $report = $this->fileReport([
            'type' => 'grouped',
            'columns' => [['field' => 'region'], ['field' => 'amount', 'format' => 'number']],
            'groups' => [['field' => 'region', 'layout' => 'above']],
            'aggregates' => [['field' => 'amount', 'fn' => 'sum']],
        ], "region,amount\nNorth,100\nNorth,50\nSouth,200\n");

        $html = $this->postJson("/api/reports/{$report->id}/run")->assertOk()->json('data.html');
        $this->assertStringContainsString('Region: North', $html);
        $this->assertStringContainsString('Grand total', $html);
    }

    public function test_runs_kpi_report(): void
    {
        $this->actingWithPermissions(['reports.run']);
        $report = $this->fileReport([
            'type' => 'kpi',
            'aggregates' => [['field' => 'amount', 'fn' => 'sum', 'label' => 'Total Sales']],
        ], "region,amount\nA,100\nB,250\n");

        $html = $this->postJson("/api/reports/{$report->id}/run")->assertOk()->json('data.html');
        $this->assertStringContainsString('Total Sales', $html);
        $this->assertStringContainsString('350', $html); // 100 + 250
    }
}
