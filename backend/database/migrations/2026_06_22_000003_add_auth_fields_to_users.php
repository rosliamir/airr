<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Self-registration + approval + social login support.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // pending = registered, awaiting approval/assignment; active; suspended
            $table->string('status')->default('active')->after('email');
            $table->string('auth_provider')->default('local')->after('status'); // local | google
            $table->string('google_id')->nullable()->unique()->after('auth_provider');
            $table->string('avatar_url')->nullable()->after('google_id');
            $table->string('password')->nullable()->change(); // social accounts may have no password
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'auth_provider', 'google_id', 'avatar_url']);
        });
    }
};
