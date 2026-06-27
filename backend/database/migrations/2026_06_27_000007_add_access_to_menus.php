<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M14 — menu access by user type (empty = all types) + auto-collapse flag so an
// item (e.g. authoring/studio) minimises the sidebar to icons when active.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->json('user_types')->nullable()->after('permission'); // ['system_admin','admin','user'] | null = all
            $table->boolean('auto_collapse')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn(['user_types', 'auto_collapse']);
        });
    }
};
