<?php

namespace Tests\Feature;

use App\Models\KbDocument;
use App\Models\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// M3 (FR-M3.1/M3.3/M3.6) — KB CRUD + document ingestion pipeline.
class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_and_lists_knowledge_base(): void
    {
        $this->actingWithPermissions(['kb.manage', 'kb.view']);

        $this->postJson('/api/knowledge-bases', ['name' => 'Policies', 'tags' => ['hr', 'finance']])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Policies')
            ->assertJsonPath('data.version', 1);

        $this->getJson('/api/knowledge-bases')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_requires_kb_view_permission(): void
    {
        $this->actingWithPermissions([]);
        $this->getJson('/api/knowledge-bases')->assertForbidden();
    }

    public function test_upload_ingests_markdown_into_chunks(): void // FR-M3.2/M3.3/M3.6
    {
        Storage::fake('local');
        $this->actingWithPermissions(['kb.manage']);
        $kb = KnowledgeBase::create(['name' => 'Docs', 'version' => 1]);

        $md = "# Overview\nAIRR fuses SQL and RAG.\n\n## Security\nZero-trust filtering inside PostgreSQL.";
        $file = UploadedFile::fake()->createWithContent('spec.md', $md);

        $res = $this->postJson("/api/knowledge-bases/{$kb->id}/documents", ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.status', KbDocument::STATUS_TRAINED);

        $this->assertSame(2, $res->json('data.chunk_count'));
        $this->assertDatabaseCount('kb_chunks', 2);
        // Embedding model resolved + recorded on the KB (FR-M15.8 integration).
        $this->assertNotNull($kb->fresh()->embedding_model);
    }

    public function test_upload_records_document_category(): void // FR-M3.2 taxonomy
    {
        Storage::fake('local');
        $this->actingWithPermissions(['kb.manage']);
        $kb = KnowledgeBase::create(['name' => 'Specs', 'version' => 1]);
        $file = UploadedFile::fake()->createWithContent('srs.md', "# SRS\nRequirement.");

        $this->postJson("/api/knowledge-bases/{$kb->id}/documents", ['file' => $file, 'category' => 'srs'])
            ->assertCreated()
            ->assertJsonPath('data.category', 'srs')
            ->assertJsonPath('data.source_kind', 'upload');

        $this->assertDatabaseHas('kb_documents', ['category' => 'srs', 'source_kind' => 'upload']);
    }

    public function test_other_category_keeps_custom_label(): void
    {
        Storage::fake('local');
        $this->actingWithPermissions(['kb.manage']);
        $kb = KnowledgeBase::create(['name' => 'Misc', 'version' => 1]);
        $file = UploadedFile::fake()->createWithContent('note.md', "# Note\nText.");

        $this->postJson("/api/knowledge-bases/{$kb->id}/documents", [
            'file' => $file, 'category' => 'other', 'category_label' => 'Release Note',
        ])->assertCreated()->assertJsonPath('data.category_label', 'Release Note');
    }

    public function test_categories_endpoint_lists_taxonomy(): void
    {
        $this->actingWithPermissions(['kb.view']);
        $this->getJson('/api/knowledge-bases/categories')
            ->assertOk()
            ->assertJsonPath('data.documents.srs', 'Software Requirement Spec (SRS)')
            ->assertJsonPath('data.documents.urs', 'User Requirement Spec (URS)');
    }

    public function test_ingests_db_schema_as_system_knowledge(): void // FR-M3.2b
    {
        $this->actingWithPermissions(['kb.manage']);
        $kb = \App\Models\KnowledgeBase::create(['name' => 'System', 'version' => 1]);
        $ds = \App\Models\DataSource::create([
            'name' => 'CRM', 'type' => 'postgres', 'config' => ['host' => 'h'],
            'schema_cache' => [
                ['table' => 'customers', 'columns' => [['name' => 'id', 'type' => 'integer'], ['name' => 'status', 'type' => 'integer']]],
                ['table' => 'orders', 'columns' => [['name' => 'id', 'type' => 'integer']]],
            ],
        ]);

        $res = $this->postJson("/api/knowledge-bases/{$kb->id}/system", ['source' => 'db_schema', 'data_source_id' => $ds->id])
            ->assertCreated()
            ->assertJsonPath('data.category', 'db_schema')
            ->assertJsonPath('data.source_kind', 'system')
            ->assertJsonPath('data.status', KbDocument::STATUS_TRAINED);

        $this->assertGreaterThanOrEqual(2, $res->json('data.chunk_count')); // one section per table
        $this->assertStringContainsString('customers', $kb->chunks()->first()->content);
    }

    public function test_ingests_rbac_as_system_knowledge(): void
    {
        $this->actingWithPermissions(['kb.manage']);
        $kb = \App\Models\KnowledgeBase::create(['name' => 'System', 'version' => 1]);

        $this->postJson("/api/knowledge-bases/{$kb->id}/system", ['source' => 'rbac'])
            ->assertCreated()
            ->assertJsonPath('data.category', 'rbac')
            ->assertJsonPath('data.source_kind', 'system');

        // The acting user's test-role + its permission appear in the RBAC text.
        $chunk = $kb->chunks()->first();
        $this->assertStringContainsString('Role:', (string) $chunk->heading);
        $this->assertStringContainsString('kb.manage', $chunk->content);
    }

    public function test_rejects_unsupported_file_type(): void
    {
        Storage::fake('local');
        $this->actingWithPermissions(['kb.manage']);
        $kb = KnowledgeBase::create(['name' => 'Docs', 'version' => 1]);

        // .csv is not a KB document type (that's M2 tabular ingestion).
        $file = UploadedFile::fake()->createWithContent('data.csv', "a,b\n1,2\n");
        $this->postJson("/api/knowledge-bases/{$kb->id}/documents", ['file' => $file])
            ->assertStatus(422);
    }

    public function test_new_version_increments_and_logs_history(): void // FR-M3.9
    {
        Storage::fake('local');
        $this->actingWithPermissions(['kb.manage', 'kb.view']);
        $kb = KnowledgeBase::create(['name' => 'Specs', 'version' => 1]);

        $v1 = UploadedFile::fake()->createWithContent('srs.md', "# SRS v1\nOne section.");
        $docId = $this->postJson("/api/knowledge-bases/{$kb->id}/documents", ['file' => $v1, 'category' => 'srs'])
            ->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        $v2 = UploadedFile::fake()->createWithContent('srs.md', "# SRS v2\nUpdated.\n\n## Extra\nMore.");
        $this->postJson("/api/kb-documents/{$docId}/versions", ['file' => $v2, 'note' => 'Added Extra section'])
            ->assertCreated()->assertJsonPath('data.version', 2);

        // History log has both versions, newest first.
        $history = $this->getJson("/api/kb-documents/{$docId}/versions")->assertOk();
        $history->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.0.note', 'Added Extra section')
            ->assertJsonPath('data.1.version', 1);

        // Active chunks reflect the latest version only.
        $this->assertStringContainsString('Updated', $kb->chunks()->get()->pluck('content')->implode(' '));
    }

    public function test_reindex_rebuilds_chunks(): void
    {
        Storage::fake('local');
        $this->actingWithPermissions(['kb.manage']);
        $kb = KnowledgeBase::create(['name' => 'Docs', 'version' => 1]);
        $file = UploadedFile::fake()->createWithContent('a.md', "# A\nHello.");

        $docId = $this->postJson("/api/knowledge-bases/{$kb->id}/documents", ['file' => $file])->json('data.id');

        $this->postJson("/api/kb-documents/{$docId}/reindex")
            ->assertOk()
            ->assertJsonPath('data.status', KbDocument::STATUS_TRAINED);

        $this->assertDatabaseCount('kb_chunks', 1); // not duplicated
    }

    public function test_delete_cascades_documents_and_chunks(): void
    {
        Storage::fake('local');
        $this->actingWithPermissions(['kb.manage']);
        $kb = KnowledgeBase::create(['name' => 'Docs', 'version' => 1]);
        $file = UploadedFile::fake()->createWithContent('a.md', "# A\nHello world.");
        $this->postJson("/api/knowledge-bases/{$kb->id}/documents", ['file' => $file])->assertCreated();

        $this->deleteJson("/api/knowledge-bases/{$kb->id}")->assertNoContent();

        $this->assertDatabaseCount('knowledge_bases', 0);
        $this->assertDatabaseCount('kb_documents', 0);
        $this->assertDatabaseCount('kb_chunks', 0);
    }
}
