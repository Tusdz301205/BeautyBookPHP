<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unique(['id', 'booking_id'], 'reviews_id_booking_unique');
        });

        Schema::create('review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_file_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('review_id')->nullable();
            $table->unsignedTinyInteger('sort_order')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['booking_id', 'review_id']);
            $table->unique(['review_id', 'sort_order']);
            $table->foreign(['review_id', 'booking_id'])->references(['id', 'booking_id'])->on('reviews')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE review_photos ADD CONSTRAINT review_photos_order_check CHECK (
            (review_id IS NULL AND sort_order IS NULL)
            OR (review_id IS NOT NULL AND sort_order IS NOT NULL AND sort_order BETWEEN 0 AND 4)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('review_photos');
        Schema::table('reviews', fn (Blueprint $table) => $table->dropUnique('reviews_id_booking_unique'));
    }
};
