<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FR-M14.5: per-project AI configuration. Each project may override the system
// default model per task (embedding/generation/reasoning/audit); null/empty
// means inherit the system default. Resolved via resolveModel($project, $task).
// Shape: { "provider": "ollama"|null, "models": { "embedding": "...", "generation": "...", "reasoning": "...", "audit": "..." } }
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->jsonb('ai_config')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('ai_config');
        });
    }
};
