<?php

namespace Tests\Feature;

use App\Models\Constant;
use App\Models\DataSource;
use App\Models\Dataset;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

// Constants: {{TYPE:KEY}} placeholders consumed by Templates/Reports —
// text, data-bound, and image types across system/global/project scope.
class ConstantTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_text_constant(): void
    {
        $this->actingWithPermissions(['settings.manage']);

        $res = $this->postJson('/api/constants', [
            'scope' => 'global', 'type' => 'text', 'key' => 'COMPANY_NAME', 'label' => 'Company Name', 'value' => 'AIRR Sdn Bhd',
        ])->assertCreated();

        $this->assertSame('{{GLOBAL:COMPANY_NAME}}', $res->json('data.placeholder'));
    }

    public function test_data_type_requires_datasource_dataset_column(): void
    {
        $this->actingWithPermissions(['settings.manage']);

        $this->postJson('/api/constants', [
            'scope' => 'global', 'type' => 'data', 'key' => 'CUSTOMER', 'label' => 'Customer',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['data_source_id', 'dataset_id', 'data_column']);
    }

    public function test_data_type_validates_column_against_dataset_fields(): void
    {
        $this->actingWithPermissions(['settings.manage']);
        $source = DataSource::create(['name' => 'S', 'type' => 'postgres', 'config' => []]);
        $dataset = Dataset::create([
            'data_source_id' => $source->id, 'name' => 'D', 'query' => 'select 1',
            'fields' => [['name' => 'customer_name'], ['name' => 'total']],
        ]);

        $this->postJson('/api/constants', [
            'scope' => 'global', 'type' => 'data', 'key' => 'CUSTOMER', 'label' => 'Customer',
            'data_source_id' => $source->id, 'dataset_id' => $dataset->id, 'data_column' => 'not_a_column',
        ])->assertStatus(422);

        $this->postJson('/api/constants', [
            'scope' => 'global', 'type' => 'data', 'key' => 'CUSTOMER', 'label' => 'Customer',
            'data_source_id' => $source->id, 'dataset_id' => $dataset->id, 'data_column' => 'customer_name',
        ])->assertCreated();
    }

    public function test_image_type_stores_upload(): void
    {
        $this->actingWithPermissions(['settings.manage']);

        $res = $this->post('/api/constants', [
            'scope' => 'global', 'type' => 'image', 'key' => 'LOGO', 'label' => 'Logo',
            'image' => UploadedFile::fake()->image('logo.png'),
        ])->assertCreated();

        $this->assertNotEmpty($res->json('data.image_url'));
    }

    public function test_project_scope_gated_by_edition(): void
    {
        config(['airr.features.project_constants' => ['enterprise']]);
        config(['airr.edition' => 'standard']);
        $this->actingWithPermissions(['settings.manage']);
        $project = Project::create(['code' => 'P1', 'name' => 'P', 'status' => 'active']);

        $this->postJson('/api/constants', [
            'scope' => 'project', 'project_id' => $project->id, 'type' => 'text', 'key' => 'X', 'label' => 'X', 'value' => 'v',
        ])->assertForbidden();
    }

    public function test_system_constant_cannot_be_deleted(): void
    {
        $this->actingWithPermissions(['settings.manage']);
        $dateConstant = Constant::where('scope', 'system')->where('key', 'DATE')->firstOrFail();

        $this->deleteJson("/api/constants/{$dateConstant->id}")->assertStatus(403);
    }

    public function test_update_writes_history_snapshot(): void
    {
        $this->actingWithPermissions(['settings.manage']);
        $id = $this->postJson('/api/constants', [
            'scope' => 'global', 'type' => 'text', 'key' => 'FOO', 'label' => 'Foo', 'value' => 'v1',
        ])->json('data.id');

        $this->putJson("/api/constants/{$id}", [
            'scope' => 'global', 'type' => 'text', 'key' => 'FOO', 'label' => 'Foo', 'value' => 'v2',
        ])->assertOk();

        $history = $this->getJson("/api/constants/{$id}/history")->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertSame('v2', $history[0]['snapshot']['value']);
    }
}
