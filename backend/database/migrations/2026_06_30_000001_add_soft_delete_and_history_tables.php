<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::create('data_source_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained('data_sources')->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users');
            $table->string('action', 30)->default('updated'); // created / updated / uploaded
            $table->json('snapshot');
            $table->timestamps();
        });

        Schema::create('dataset_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users');
            $table->string('action', 30)->default('updated');
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_histories');
        Schema::dropIfExists('data_source_histories');
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
