<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->text('header')->nullable();
            $table->text('body')->nullable();
            $table->text('footer')->nullable();
            $table->text('group_header')->nullable();
            $table->text('group_footer')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('constants', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20); // system | global | project
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('label', 160);
            $table->text('value')->nullable();
            $table->string('format', 80)->nullable();
            $table->integer('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['scope', 'project_id', 'key']);
        });

        // Seed system constants.
        \DB::table('constants')->insertOrIgnore([
            ['scope' => 'system', 'project_id' => null, 'key' => 'DATE',      'label' => 'Current Date',     'format' => 'd/m/Y',     'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['scope' => 'system', 'project_id' => null, 'key' => 'TIME',      'label' => 'Current Time',     'format' => 'H:i',       'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['scope' => 'system', 'project_id' => null, 'key' => 'DATETIME',  'label' => 'Current DateTime', 'format' => 'd/m/Y H:i', 'sort' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['scope' => 'system', 'project_id' => null, 'key' => 'YEAR',      'label' => 'Current Year',     'format' => 'Y',         'sort' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['scope' => 'system', 'project_id' => null, 'key' => 'USER_NAME', 'label' => 'Current User',     'format' => null,        'sort' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['scope' => 'system', 'project_id' => null, 'key' => 'PAGE',      'label' => 'Page Number',      'format' => null,        'sort' => 6, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('constants');
        Schema::dropIfExists('templates');
    }
};
