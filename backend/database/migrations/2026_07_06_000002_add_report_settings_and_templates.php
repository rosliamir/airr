<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(0)->after('status');
            $table->string('layout', 20)->default('portrait')->after('version'); // portrait | landscape
            $table->string('printout_size', 20)->default('a4')->after('layout'); // a4 | a3 | a2 | b5 | custom
            $table->unsignedInteger('printout_width')->nullable()->after('printout_size');
            $table->unsignedInteger('printout_height')->nullable()->after('printout_width');
            $table->json('output_formats')->nullable()->after('printout_height'); // ['pdf','excel','csv']
            $table->boolean('locked')->default(false)->after('output_formats');
        });

        Schema::create('report_template', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['report_id', 'template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_template');
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['version', 'layout', 'printout_size', 'printout_width', 'printout_height', 'output_formats', 'locked']);
        });
    }
};
