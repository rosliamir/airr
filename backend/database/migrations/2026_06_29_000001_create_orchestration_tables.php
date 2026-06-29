<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M4 (FR-M4.1–FR-M4.11) — orchestration run state and per-step audit trail.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orchestration_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();

            $table->text('prompt');
            $table->json('intent')->nullable();
            $table->json('context_fusion')->nullable();

            // queued | running | auditing | complete | failed | rejected
            $table->string('status', 20)->default('queued');
            $table->text('output_html')->nullable();
            $table->text('executive_summary')->nullable();
            $table->text('compliance_notes')->nullable();
            $table->boolean('compliance_passed')->nullable();

            $table->text('error')->nullable();
            $table->unsignedInteger('total_duration_ms')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('created_by');
        });

        Schema::create('orchestration_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orchestration_run_id')->constrained()->cascadeOnDelete();

            // retriever | data_analyst | auditor | writer | compliance
            $table->string('agent', 30);
            $table->unsignedTinyInteger('sequence');

            // running | complete | failed | skipped
            $table->string('status', 20);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('model_used')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['orchestration_run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orchestration_steps');
        Schema::dropIfExists('orchestration_runs');
    }
};
