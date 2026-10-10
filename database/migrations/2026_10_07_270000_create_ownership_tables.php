<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_identity_versions', function (Blueprint $table) {
            $table->id(); $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['LEGAL_ENTITY', 'PAYOUT_ACCOUNT']); $table->unsignedInteger('version');
            $table->longText('payload_ciphertext'); $table->string('display_label');
            $table->enum('verification_status', ['PENDING', 'VERIFIED', 'REJECTED'])->default('PENDING');
            $table->boolean('is_active')->default(false); $table->timestamp('valid_from')->nullable(); $table->timestamp('valid_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_reason')->nullable(); $table->timestamps();
            $table->unique(['business_id', 'type', 'version'], 'identity_version_unique');
        });
        Schema::create('ownership_transfers', function (Blueprint $table) {
            $table->id(); $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('old_owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('new_owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('PENDING_NEW_OWNER_ACCEPTANCE');
            $table->timestamp('effective_at'); $table->text('reason'); $table->json('impact_snapshot');
            $table->json('settlement_agreement')->nullable(); $table->timestamp('accepted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('legal_version_id')->nullable()->constrained('business_identity_versions')->restrictOnDelete();
            $table->foreignId('payout_version_id')->nullable()->constrained('business_identity_versions')->restrictOnDelete();
            $table->text('review_reason')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps();
        });
        Schema::create('ownership_history', function (Blueprint $table) {
            $table->id(); $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('ownership_transfers')->restrictOnDelete();
            $table->timestamp('valid_from'); $table->timestamp('valid_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ownership_history'); Schema::dropIfExists('ownership_transfers'); Schema::dropIfExists('business_identity_versions');
    }
};
