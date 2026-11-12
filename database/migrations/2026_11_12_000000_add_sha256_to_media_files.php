<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * media-storage.ts keeps the SHA-256 of every private object next to its size and serves the object only if both still match.
 * New uploads record it here. Rows created before this migration have none and keep being read on their size alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->char('sha256', 64)->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('sha256');
        });
    }
};
