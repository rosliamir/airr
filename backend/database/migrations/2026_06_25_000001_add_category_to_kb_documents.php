<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M3 — classify each KB item by SDLC artifact category (URS/SRS/SDS/...) and
// by how it entered the KB (upload / integration / system introspection).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_documents', function (Blueprint $table) {
            $table->string('category')->default('other')->after('type');    // key from config('kb.document_categories'|'system_sources')
            $table->string('category_label')->nullable()->after('category'); // custom label when category = other
            $table->string('source_kind')->default('upload')->after('category_label'); // upload | integration | system
            $table->index(['knowledge_base_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('kb_documents', function (Blueprint $table) {
            $table->dropIndex(['knowledge_base_id', 'category']);
            $table->dropColumn(['category', 'category_label', 'source_kind']);
        });
    }
};
