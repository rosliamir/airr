<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-dashboard access control — mirrors report_role. A dashboard with no
// rows is private to its creator (+ admins).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_run')->default(true);
            $table->timestamps();
            $table->unique(['dashboard_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_role');
    }
};
