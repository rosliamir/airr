<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ownership scoping (created_by) + Projects (FR-M14: reports are stored by project).
return new class extends Migration
{
    public function up(): void
    {
        // Who provisioned this account — drives owner+group visibility scoping.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('user_group_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->nullable();        // e.g. development | audit | migration
            $table->string('status')->default('active'); // active | on_hold | completed | archived
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Project membership — direct users and whole groups.
        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'user_id']);
        });

        Schema::create('project_user_group', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'user_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user_group');
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
