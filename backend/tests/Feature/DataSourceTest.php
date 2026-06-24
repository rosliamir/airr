<?php

namespace Tests\Feature;

use App\Models\DataSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// M2 — data source connections: encryption-at-rest, credential masking,
// validation, and file ingestion.
class DataSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_are_encrypted_at_rest(): void // FR-M2.8
    {
        $this->actingWithPermissions(['datasources.manage']);

        $this->postJson('/api/data-sources', [
            'name' => 'Prod PG',
            'type' => 'postgres',
            'config' => ['host' => 'db.internal', 'database' => 'app', 'username' => 'u', 'password' => 'sup3rsecret'],
        ])->assertCreated();

        // Raw column must not contain the plaintext password…
        $raw = DB::table('data_sources')->value('config');
        $this->assertStringNotContainsString('sup3rsecret', $raw);

        // …but the cast decrypts it back.
        $this->assertSame('sup3rsecret', DataSource::first()->config['password']);
    }

    public function test_show_masks_secrets(): void
    {
        $this->actingWithPermissions(['datasources.manage', 'datasources.view']);
        $source = DataSource::create([
            'name' => 'PG', 'type' => 'postgres',
            'config' => ['host' => 'h', 'database' => 'd', 'username' => 'u', 'password' => 'secret'],
        ]);

        $res = $this->getJson("/api/data-sources/{$source->id}")->assertOk();
        $summary = $res->json('data.config_summary');

        $this->assertArrayHasKey('host', $summary);
        $this->assertArrayNotHasKey('password', $summary);
        $res->assertJsonMissing(['password' => 'secret']);
    }

    public function test_validates_type_and_required_config_for_db(): void
    {
        $this->actingWithPermissions(['datasources.manage']);

        $this->postJson('/api/data-sources', ['name' => 'X', 'type' => 'banana'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['type']]]);

        // DB source needs config on create.
        $this->postJson('/api/data-sources', ['name' => 'X', 'type' => 'postgres'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['config']]]);
    }

    public function test_file_upload_parses_and_caches_schema(): void // FR-M2.3 / M2.4
    {
        Storage::fake('local');
        $this->actingWithPermissions(['datasources.manage']);
        $source = DataSource::create(['name' => 'CSV', 'type' => 'csv', 'config' => []]);

        $file = UploadedFile::fake()->createWithContent('people.csv', "name,age\nAli,30\nAbu,25\n");

        $res = $this->postJson("/api/data-sources/{$source->id}/upload", ['file' => $file, 'has_header' => true])
            ->assertOk();

        $this->assertSame(2, $res->json('data.config_summary.row_count'));
        $this->assertSame('ok', $res->json('data.status'));

        $source->refresh();
        $this->assertNotEmpty($source->schema_cache);
        $this->assertSame('people.csv', $source->schema_cache[0]['table']);
    }

    public function test_upload_rejected_for_non_file_source(): void
    {
        $this->actingWithPermissions(['datasources.manage']);
        $source = DataSource::create(['name' => 'PG', 'type' => 'postgres', 'config' => ['host' => 'h']]);

        $file = UploadedFile::fake()->createWithContent('x.csv', "a\n1\n");
        $this->postJson("/api/data-sources/{$source->id}/upload", ['file' => $file])
            ->assertStatus(422)->assertJsonPath('error.code', 'NOT_A_FILE_SOURCE');
    }

    public function test_requires_permission(): void
    {
        $this->actingWithPermissions([]); // authenticated, no datasources.view
        $this->getJson('/api/data-sources')->assertForbidden();
    }
}
