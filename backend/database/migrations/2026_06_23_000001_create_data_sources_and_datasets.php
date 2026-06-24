<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M2 — Data Source Connector.
//   Layer 1: data_sources = a connection (DB or API), credentials encrypted.
//   Layer 2: datasets     = a saved query/call under a source, with the SQL/API
//            definition, field list, and runtime parameters.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type'); // postgres | mysql | sqlserver | rest_api | graphql
            $table->text('config')->nullable();        // encrypted JSON (credentials / base url)
            $table->json('schema_cache')->nullable();   // introspected tables/columns
            $table->string('status')->default('unknown'); // unknown | ok | error
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->text('query')->nullable();   // SQL (DB) or path/template (API)
            $table->string('method')->nullable(); // GET | POST (API only)
            $table->text('body')->nullable();     // request body template (API POST)
            $table->json('fields')->nullable();      // [{name,label,type}]
            $table->json('parameters')->nullable();  // [{name,label,type,default,required}]
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
        Schema::dropIfExists('data_sources');
    }
};
