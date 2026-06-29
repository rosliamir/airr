<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M6/M14 — a report can be linked to MANY projects (many-to-many access).
// reports.project_id stays as the "home" project; this pivot is the access list.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_report', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'report_id']);
        });

        // Backfill: link each existing report to its home project.
        $rows = DB::table('reports')->whereNotNull('project_id')->get(['id', 'project_id']);
        foreach ($rows as $r) {
            DB::table('project_report')->insertOrIgnore([
                'project_id' => $r->project_id, 'report_id' => $r->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_report');
    }
};
