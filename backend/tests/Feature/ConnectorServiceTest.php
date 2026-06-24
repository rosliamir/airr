<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\DataSource;
use App\Services\ConnectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// M2 (FR-M2.5) — dataset execution: SELECT guardrail + file dataset filtering.
class ConnectorServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ConnectorService
    {
        return app(ConnectorService::class);
    }

    public function test_rejects_non_select_queries(): void // guardrail
    {
        $source = DataSource::create(['name' => 'PG', 'type' => 'postgres', 'config' => ['host' => 'h']]);
        $dataset = Dataset::create(['data_source_id' => $source->id, 'name' => 'bad', 'query' => 'DELETE FROM users']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only SELECT');
        $this->service()->run($dataset, []);
    }

    public function test_rejects_multiple_statements(): void
    {
        $source = DataSource::create(['name' => 'PG', 'type' => 'postgres', 'config' => ['host' => 'h']]);
        $dataset = Dataset::create(['data_source_id' => $source->id, 'name' => 'multi', 'query' => 'select 1; select 2']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Multiple statements');
        $this->service()->run($dataset, []);
    }

    public function test_file_dataset_filters_by_param(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data_files/p.csv', "name,city\nAli,KL\nAbu,JB\nSiti,KL\n");

        $source = DataSource::create([
            'name' => 'CSV', 'type' => 'csv',
            'config' => ['file_path' => 'data_files/p.csv', 'original_name' => 'p.csv', 'options' => ['has_header' => true]],
        ]);
        $dataset = Dataset::create([
            'data_source_id' => $source->id, 'name' => 'by-city',
            'parameters' => [['name' => 'city', 'type' => 'text']],
        ]);

        $all = $this->service()->run($dataset, []);
        $this->assertSame(3, $all['count']);

        $kl = $this->service()->run($dataset, ['city' => 'KL']);
        $this->assertSame(2, $kl['count']);
        $this->assertSame(['name', 'city'], $kl['columns']);
    }
}
