<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A viewer of a published report's shareable link can prompt-edit their own
// personalized copy without needing edit rights on (or touching) the source
// report — this is that personal snapshot. Deliberately NOT named "template",
// which already means something else (Templates module: header/footer/body
// layout snippets). Each save gets its own id/URL, reopenable by its owner.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('definition');
            $table->text('prompt')->nullable();
            $table->json('prompt_history')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
