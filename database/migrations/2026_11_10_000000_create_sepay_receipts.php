<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A business's own receiving account for direct deposits (payment_connections in the API package). The webhook
        // secret is stored encrypted (AES-256-GCM, bound to the row id) and shown to the owner exactly once.
        Schema::create('payment_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('environment', 4);
            $table->string('bank_code', 32);
            $table->string('account_number', 64);
            $table->string('account_name', 120);
            $table->text('encrypted_secret');
            $table->string('verification_code', 32)->unique();
            $table->string('status', 16)->default('SETUP');
            $table->boolean('enabled')->default(false);
            $table->dateTime('verified_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            // At most one connection per business takes new deposits.
            $table->unsignedBigInteger('enabled_business_key')->nullable()->virtualAs('IF(enabled, business_id, NULL)')->unique('payment_connection_one_enabled');
            $table->index(['business_id', 'created_at']);
        });
        DB::statement("ALTER TABLE payment_connections ADD CONSTRAINT payment_connection_values CHECK (environment IN ('test', 'live') AND status IN ('SETUP', 'READY') AND (NOT enabled OR (status = 'READY' AND verified_at IS NOT NULL)))");

        // Durable inbox of authenticated bank receipts (sepay_webhook_events in the API package). The ordinary
        // endpoint only stores here (channel LEGACY, REVIEW_REQUIRED); only the private wallet channel credits money.
        Schema::create('sepay_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->enum('environment', ['test', 'live']);
            $table->unsignedBigInteger('provider_transaction_id');
            $table->string('channel', 24)->default('LEGACY');
            $table->string('gateway', 100);
            $table->string('account_number', 64);
            $table->string('sub_account', 128)->nullable();
            $table->dateTime('transaction_at');
            $table->enum('transfer_type', ['in', 'out']);
            $table->unsignedBigInteger('amount_vnd');
            $table->string('content', 2000);
            $table->string('payment_code', 128)->nullable();
            $table->string('reference_code', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 24)->default('REVIEW_REQUIRED');
            $table->string('processing', 24)->default('UNMATCHED');
            $table->string('review_reason', 48)->nullable();
            $table->unsignedBigInteger('top_up_id')->nullable();
            // Direct-deposit receipts belong to one connection and (once matched) to one deposit.
            $table->foreignId('connection_id')->nullable()->constrained('payment_connections')->restrictOnDelete();
            $table->unsignedBigInteger('deposit_id')->nullable();
            $table->char('fingerprint', 64);
            $table->char('payload_sha256', 64);
            $table->dateTime('received_at');
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            // Replays of one bank transaction can never create a second receipt: per connection for direct deposits, per
            // environment for the legacy and wallet channels (the generated key is NULL for connection receipts).
            $table->unique(['connection_id', 'environment', 'provider_transaction_id'], 'sepay_connection_event_identity');
            $table->string('legacy_identity', 40)->nullable()->virtualAs("IF(connection_id IS NULL, CONCAT(environment, ':', provider_transaction_id), NULL)")->unique('sepay_legacy_event_identity');
            // A deposit is paid by exactly one receipt.
            $table->unsignedBigInteger('applied_deposit_key')->nullable()->virtualAs("IF(processing = 'APPLIED', deposit_id, NULL)")->unique('deposit_one_applied_receipt');
            $table->index(['channel', 'processing', 'received_at']);
            $table->index('top_up_id');
        });
    }

    public function down(): void
    {
        // Receipts are financial evidence. Roll back code first; drop only on an empty environment.
        Schema::dropIfExists('sepay_webhook_events');
        Schema::dropIfExists('payment_connections');
    }
};
