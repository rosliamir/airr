<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('constants', function (Blueprint $table) {
            $table->string('type', 20)->default('text')->after('scope'); // text | data | image
            $table->foreignId('data_source_id')->nullable()->after('value')->constrained('data_sources')->nullOnDelete();
            $table->foreignId('dataset_id')->nullable()->after('data_source_id')->constrained('datasets')->nullOnDelete();
            $table->string('data_column', 120)->nullable()->after('dataset_id');
            $table->string('image_path', 255)->nullable()->after('data_column');
        });

        Schema::create('constant_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('constant_id')->constrained('constants')->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users');
            $table->string('action', 30);
            $table->json('snapshot');
            $table->timestamps();
        });

        // Additive: constants supported by the resolver but never seeded.
        \DB::table('constants')->insertOrIgnore([
            ['scope' => 'system', 'type' => 'text', 'project_id' => null, 'key' => 'USER_EMAIL',    'label' => 'Current User Email', 'format' => null, 'sort' => 7, 'created_at' => now(), 'updated_at' => now()],
            ['scope' => 'system', 'type' => 'text', 'project_id' => null, 'key' => 'TOTAL_PAGE_NO', 'label' => 'Total Pages',        'format' => null, 'sort' => 8, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('constant_histories');
        Schema::table('constants', function (Blueprint $table) {
            $table->dropForeign(['data_source_id']);
            $table->dropForeign(['dataset_id']);
            $table->dropColumn(['type', 'data_source_id', 'dataset_id', 'data_column', 'image_path']);
        });
    }
};
