<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M6 (FR-M6 ACL) — per-report access control. Each row grants a role a set of
// abilities on a specific report ("permission mengikut item"). A report with no
// rows is private to its creator (+ admins).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_run')->default(true);
            $table->timestamps();
            $table->unique(['report_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_role');
    }
};
