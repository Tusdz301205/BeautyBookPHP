<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('overall_rating');
            $table->text('comment');
            $table->boolean('is_anonymous')->default(false);
            $table->json('service_ratings')->nullable();
            $table->enum('status', ['APPROVED', 'REPORTED', 'HIDDEN'])->default('APPROVED');
            $table->text('business_reply')->nullable();
            $table->foreignId('replied_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('category', 30);
            $table->string('severity', 20);
            $table->timestamps();
            $table->unique(['review_id', 'reporter_id']);
        });
        Schema::create('review_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->restrictOnDelete();
            $table->foreignId('appellant_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->text('resolution')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('review_moderation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason_code', 80);
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('saved_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_service_offering_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['customer_profile_id', 'branch_service_offering_id'], 'saved_services_customer_offering_unique');
        });
    }

    public function down(): void
    {
        foreach (['saved_services', 'review_moderation_events', 'review_appeals', 'review_reports', 'reviews'] as $table) Schema::dropIfExists($table);
    }
};
