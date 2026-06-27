<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module readiness board — reviewer-toggled status per feature (human test +
// production readiness). Build facts (developed/ai_tested) live in config/features.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key')->unique();
            $table->boolean('human_tested')->default(false);
            $table->boolean('ready_for_prod')->default(false);
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_statuses');
    }
};
