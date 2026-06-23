<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Project: customer name + colour code (visual identification).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('customer_name')->nullable()->after('name');
            $table->string('color', 9)->nullable()->after('type'); // hex e.g. #E11D48
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'color']);
        });
    }
};
