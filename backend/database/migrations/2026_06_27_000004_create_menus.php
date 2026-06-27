<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M14 — DB-driven navigation. A menu item belongs to an optional parent
// (1:M self-reference for grouping/hierarchy) and is shown only when the user
// holds `permission` (null = visible to any authenticated user) and the active
// edition enables `feature` (null = always).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->nullOnDelete();
            $table->string('label');
            $table->string('route')->nullable();     // SPA route name (null for a heading/group)
            $table->string('icon')->nullable();       // lucide icon name
            $table->string('module')->nullable();     // Mx tag
            $table->string('permission')->nullable(); // required RBAC permission key
            $table->string('feature')->nullable();    // required edition feature flag
            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['parent_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
