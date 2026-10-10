<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', fn (Blueprint $table) => $table->string('phone', 32)->nullable());
        Schema::table('users', fn (Blueprint $table) => $table->foreignId('avatar_media_file_id')->nullable()->constrained('media_files')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('avatar_media_file_id'));
        Schema::table('customer_profiles', fn (Blueprint $table) => $table->dropColumn('phone'));
    }
};
