<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One wallet per customer and scope. DEMO and LIVE_LAB never mix balances.
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->enum('scope', ['DEMO', 'LIVE_LAB'])->default('DEMO');
            $table->unsignedBigInteger('available_vnd')->default(0);
            $table->unsignedBigInteger('held_vnd')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['customer_profile_id', 'scope']);
        });
        // Append-only ledger: one equal debit/credit pair per transfer; the operation key makes a replay post once.
        Schema::create('wallet_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('operation_key', 150)->unique();
            $table->string('kind', 24);
            $table->string('from_account', 100);
            $table->string('to_account', 100);
            $table->unsignedBigInteger('amount_vnd');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 200);
            $table->unsignedBigInteger('available_after_vnd')->nullable();
            $table->unsignedBigInteger('held_after_vnd')->nullable();
            $table->unsignedInteger('wallet_version')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['wallet_id', 'wallet_version']);
            $table->index(['wallet_id', 'created_at']);
        });
        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->char('key_hash', 64);
            $table->char('fingerprint', 64);
            $table->string('code', 32)->unique();
            $table->unsignedBigInteger('amount_vnd');
            $table->string('provider', 12);
            $table->enum('status', ['PENDING', 'SUCCESS', 'EXPIRED', 'FAILED'])->default('PENDING');
            $table->dateTime('expires_at');
            $table->dateTime('completed_at')->nullable();
            $table->string('receipt_identity', 200)->nullable()->unique();
            $table->foreignId('bank_event_id')->nullable()->unique()->constrained('sepay_webhook_events')->restrictOnDelete();
            $table->json('receiver_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['wallet_id', 'key_hash']);
            $table->index(['status', 'expires_at']);
        });
        // The receipt row points at the invoice it matched (manual review keeps the candidate).
        Schema::table('sepay_webhook_events', function (Blueprint $table) {
            $table->foreign('top_up_id')->references('id')->on('wallet_topups')->restrictOnDelete();
        });
        // Deposit held from the wallet at booking time (30% of the payable total), released to the business on completion.
        Schema::create('booking_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('funding_source', 24)->default('SIMULATED_WALLET');
            $table->string('payment_code', 32)->unique();
            $table->unsignedBigInteger('total_vnd');
            $table->unsignedBigInteger('amount_vnd');
            $table->unsignedBigInteger('remaining_vnd');
            $table->unsignedInteger('rate_bps')->default(3000);
            // Wallet deposits are HELD -> APPLIED/FORFEITED/REFUNDED. Direct deposits start AWAITING_PAYMENT and are PAID by one
            // bank receipt; unpaid ones end EXPIRED or VOIDED, paid ones APPLIED/FORFEITED or REFUND_DUE -> REFUNDED.
            $table->enum('status', ['AWAITING_PAYMENT', 'PAID', 'HELD', 'APPLIED', 'FORFEITED', 'EXPIRED', 'VOIDED', 'REFUND_DUE', 'REFUNDED'])->default('HELD');
            $table->foreignId('connection_id')->nullable()->constrained('payment_connections')->restrictOnDelete();
            // Receiving account snapshot: a later change of the business's account never alters a deposit that exists.
            $table->string('bank_code', 32)->nullable();
            $table->string('account_number', 64)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->string('environment', 4)->default('test');
            $table->string('confirmation_mode', 6);
            $table->unsignedInteger('pending_hold_minutes');
            $table->unsignedInteger('cancellation_hours');
            $table->dateTime('expires_at');
            $table->dateTime('paid_at')->nullable();
            // The store has recorded the remainder: a deposit with a store payment can no longer be cancelled away.
            $table->dateTime('store_received_at')->nullable();
            $table->foreignId('store_received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('store_reference', 200)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::table('sepay_webhook_events', function (Blueprint $table) {
            $table->foreign('deposit_id')->references('id')->on('booking_deposits')->restrictOnDelete();
        });
        Schema::create('deposit_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_id')->constrained('booking_deposits')->restrictOnDelete();
            $table->string('source', 24)->default('SIMULATED_WALLET');
            // Wallet refunds are keyed by operation; direct refunds by the receipt that is owed back (one refund per receipt).
            $table->string('operation_key', 150)->nullable()->unique();
            $table->foreignId('event_id')->nullable()->unique()->constrained('sepay_webhook_events')->restrictOnDelete();
            $table->unsignedBigInteger('amount_vnd');
            $table->string('reason', 40);
            $table->string('status', 16)->default('PENDING');
            // Where the customer asked to be paid back (direct deposits): bank, account number, account name.
            $table->json('destination')->nullable();
            $table->string('reference', 200)->nullable();
            $table->dateTime('reported_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->index(['deposit_id', 'status']);
        });
    }

    public function down(): void
    {
        // Wallet balances and ledgers are financial evidence: roll back code first and drop these only on an empty environment.
        Schema::table('sepay_webhook_events', fn (Blueprint $table) => $table->dropForeign(['top_up_id']));
        Schema::table('sepay_webhook_events', fn (Blueprint $table) => $table->dropForeign(['deposit_id']));
        foreach (['deposit_refunds', 'booking_deposits', 'wallet_topups', 'wallet_transfers', 'wallets'] as $table) Schema::dropIfExists($table);
    }
};
