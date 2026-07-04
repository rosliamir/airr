<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('constants', function (Blueprint $table) {
            $table->text('formula')->nullable()->after('data_column'); // type=calc: expression referencing other {{TOKENS}}
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('data_source_id')->nullable()->after('project_id')->constrained('data_sources')->nullOnDelete();
            $table->foreignId('dataset_id')->nullable()->after('data_source_id')->constrained('datasets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['data_source_id']);
            $table->dropForeign(['dataset_id']);
            $table->dropColumn(['data_source_id', 'dataset_id']);
        });

        Schema::table('constants', function (Blueprint $table) {
            $table->dropColumn('formula');
        });
    }
};
