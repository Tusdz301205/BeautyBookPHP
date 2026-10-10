<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_preferences', function (Blueprint $table) {
            $table->foreignId('customer_profile_id')->primary()->constrained()->restrictOnDelete();
            foreach (['email_marketing', 'sms_marketing', 'push_marketing', 'personalized_promotions'] as $field) $table->boolean($field)->default(false);
            $table->timestamps();
        });
        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['EXPORT', 'RECTIFICATION', 'ERASURE', 'RESTRICT_PROCESSING', 'OBJECT_PROCESSING', 'DELETE_ACCOUNT']);
            $table->enum('status', ['RECEIVED', 'IDENTITY_VERIFICATION', 'IN_PROGRESS', 'COMPLETED', 'REJECTED'])->default('RECEIVED');
            $table->text('reason')->nullable();
            $table->timestamp('identity_verified_at')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->index(['customer_profile_id', 'status']);
        });
        Schema::create('privacy_export_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->constrained('data_subject_requests')->restrictOnDelete();
            $table->char('download_token_hash', 64);
            $table->longText('payload_ciphertext');
            $table->timestamp('expires_at');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_export_packages');
        Schema::dropIfExists('data_subject_requests');
        Schema::dropIfExists('marketing_preferences');
    }
};
