<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M6 (FR-M6.1) — a report is a portable JSON definition bound to a dataset.
// The human-readable `definition` (columns/groups/filters/sorts/aggregates/
// params/conditional/ai_hints) is what the Studio (M5) edits and the renderer
// (M6.2) executes; on publish it later compiles to a CRA (M7).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dataset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('type')->default('table'); // table | grouped | kpi | matrix | chart | document
            $table->jsonb('definition')->nullable();   // full report definition
            $table->string('status')->default('draft'); // draft | published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
