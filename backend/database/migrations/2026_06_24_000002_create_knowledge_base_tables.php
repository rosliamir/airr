<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M3 — Knowledge Base / RAG (structure only; the pgvector `embedding` column +
// HNSW index live in a separate migration that requires CREATE EXTENSION vector).
//   knowledge_bases      = a KB/collection (versioned, taggable) — FR-M3.1/M3.9
//   kb_documents         = an uploaded reference doc + ingestion status — FR-M3.2/M3.6
//   kb_chunks            = structure-aware chunks to be embedded — FR-M3.3/M3.5
//   semantic_schema_cache = business term -> table/column mapping — FR-M3.7
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('tags')->nullable();              // FR-M3.9 tagging
            $table->integer('version')->default(1);        // FR-M3.9 versioning
            $table->string('clearance')->nullable();       // FR-M3.10 zero-trust access level
            $table->string('embedding_model')->nullable(); // resolved via ModelResolver at ingest time
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('kb_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type');                        // pdf | docx | xlsx | markdown
            $table->string('path')->nullable();            // stored upload path
            $table->string('status')->default('queued');   // queued | processing | trained | error — FR-M3.6
            $table->text('error')->nullable();
            $table->integer('chunk_count')->default(0);
            $table->timestamp('trained_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('kb_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kb_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->integer('ordinal')->default(0);        // position within the document
            $table->string('heading')->nullable();         // structure-aware chunk heading — FR-M3.3
            $table->text('content');                       // text (diagrams/tables already -> text, FR-M3.4)
            $table->json('metadata')->nullable();          // {page, source, type}
            // NOTE: `embedding vector(N)` column + HNSW index added in the
            // pgvector migration (infra gate) — FR-M3.5.
            $table->timestamps();
        });

        Schema::create('semantic_schema_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('term');                        // business term, e.g. "active customer"
            $table->string('table_name')->nullable();
            $table->string('column_name')->nullable();
            $table->text('definition')->nullable();        // e.g. "status = 1"
            $table->timestamps();
            $table->index(['data_source_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semantic_schema_cache');
        Schema::dropIfExists('kb_chunks');
        Schema::dropIfExists('kb_documents');
        Schema::dropIfExists('knowledge_bases');
    }
};
