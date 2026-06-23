<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FR-M14.4: user_type (classification, distinct from permission-bearing roles)
// + managed user groups for org/department scoping.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // system_admin | admin | user — a label, NOT an access grant.
            $table->string('user_type')->default('user')->after('email');
            $table->foreignId('user_group_id')->nullable()->after('user_type')
                ->constrained('user_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_group_id');
            $table->dropColumn('user_type');
        });
        Schema::dropIfExists('user_groups');
    }
};
