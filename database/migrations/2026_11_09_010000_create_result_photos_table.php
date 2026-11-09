<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_service_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_file_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('booking_service_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subject_reference', 1000)->nullable();
            $table->foreignId('proof_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->dateTime('consent_captured_at', 3)->nullable();
            $table->string('consent_source', 1000)->nullable();
            $table->string('consent_version', 120)->nullable();
            $table->foreignId('consent_captured_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at', 3)->nullable();
            $table->foreignId('published_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['branch_service_offering_id', 'media_file_id'], 'result_photos_offering_media_unique');
        });

        DB::statement("ALTER TABLE result_photos ADD CONSTRAINT result_photos_provenance_check CHECK (
            (booking_id IS NOT NULL AND customer_profile_id IS NOT NULL AND subject_reference IS NULL)
            OR (booking_id IS NULL AND booking_service_id IS NULL AND customer_profile_id IS NULL
                AND subject_reference IS NOT NULL AND CHAR_LENGTH(TRIM(subject_reference)) > 0 AND proof_media_id IS NOT NULL)
        ), ADD CONSTRAINT result_photos_publication_check CHECK (
            published_at IS NULL OR (revoked_at IS NULL AND published_by_id IS NOT NULL
                AND consent_captured_at IS NOT NULL AND consent_captured_at <= published_at
                AND consent_captured_by_id IS NOT NULL AND consent_source IS NOT NULL AND consent_version IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('result_photos');
    }
};
