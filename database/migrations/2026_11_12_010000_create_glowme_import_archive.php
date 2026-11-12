<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glowme_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_database');
            $table->char('source_sha256', 64);
            $table->timestamp('source_exported_at');
            $table->string('status', 24);
            $table->json('manifest');
            $table->json('report')->nullable();
            $table->timestamps();
        });
        Schema::create('glowme_import_rows', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 128);
            $table->unsignedInteger('row_number');
            // LONGTEXT keeps exact numeric lexemes and every source field, including unsupported domains.
            $table->longText('payload');
            $table->char('sha256', 64);
            $table->unique(['source_table', 'row_number']);
        });
        Schema::create('glowme_import_ids', function (Blueprint $table) {
            $table->string('source_table', 128);
            $table->string('source_id', 128);
            $table->unsignedBigInteger('local_id');
            $table->primary(['source_table', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glowme_import_ids');
        Schema::dropIfExists('glowme_import_rows');
        Schema::dropIfExists('glowme_import_runs');
    }
};
