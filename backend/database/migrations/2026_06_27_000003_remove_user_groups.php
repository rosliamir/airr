<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RBAC simplification — drop the Groups concept entirely. Users relate to Roles
// (and Projects) directly; visibility scoping no longer uses groups.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('project_user_group');

        if (Schema::hasColumn('users', 'user_group_id')) {
            Schema::table('users', function (Blueprint $table) {
                // Drop FK then column (guard for sqlite which has no named FK).
                try {
                    $table->dropForeign(['user_group_id']);
                } catch (\Throwable $e) {
                    // sqlite / no FK — ignore
                }
                $table->dropColumn('user_group_id');
            });
        }

        Schema::dropIfExists('user_groups');
    }

    public function down(): void
    {
        // One-way simplification; recreate manually if ever needed.
    }
};
