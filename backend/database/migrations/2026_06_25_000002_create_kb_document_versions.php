<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M3 (FR-M3.9) — per-document version history. Each time a document is updated
// (a newer file uploaded), a version row is recorded as an immutable log; the
// document's active chunks always reflect the latest version.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_documents', function (Blueprint $table) {
            $table->integer('version')->default(1)->after('source_kind');
        });

        Schema::create('kb_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kb_document_id')->constrained()->cascadeOnDelete();
            $table->integer('version');
            $table->string('title');
            $table->string('type');
            $table->string('path')->nullable();       // stored file for this version (kept for history/rollback)
            $table->string('status')->default('trained');
            $table->integer('chunk_count')->default(0);
            $table->string('note')->nullable();        // optional change note
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kb_document_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_document_versions');
        Schema::table('kb_documents', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
