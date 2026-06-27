<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M14 — generic lookup/reference values managed in Settings. Each lookup belongs
// to a category (e.g. user_type, project_type) and feeds dropdowns across the app.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookups', function (Blueprint $table) {
            $table->id();
            $table->string('category');         // user_type | project_type | ...
            $table->string('value');            // stable key stored on records
            $table->string('label');            // display label
            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // system values can't be deleted
            $table->timestamps();
            $table->unique(['category', 'value']);
            $table->index(['category', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookups');
    }
};
