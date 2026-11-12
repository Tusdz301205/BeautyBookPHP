<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // What the platform owes a business: one row per released (completed or forfeited) booking deposit.
        Schema::create('business_payables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_id')->unique()->constrained('booking_deposits')->restrictOnDelete();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_vnd');
            $table->string('reason', 40);
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'settlement_id', 'created_at']);
        });
        // Submitted by the owner (PENDING), verified by a platform admin (VERIFIED_DEMO, default); a replacement is a new row.
        Schema::create('business_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('bank_code', 40);
            $table->string('bank_name', 120);
            $table->string('account_number', 64);
            $table->string('account_name', 120);
            $table->enum('status', ['PENDING', 'VERIFIED_DEMO', 'DISABLED'])->default('PENDING');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });
        Schema::create('business_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('business_bank_accounts')->restrictOnDelete();
            $table->enum('wallet_scope', ['DEMO', 'LIVE_LAB'])->default('LIVE_LAB');
            $table->string('operation_key', 150)->unique();
            $table->char('fingerprint', 64);
            $table->unsignedBigInteger('amount_vnd');
            $table->enum('status', ['PENDING_APPROVAL', 'PROCESSING', 'FAILED', 'SIMULATED_COMPLETED', 'REJECTED', 'CANCELLED'])->default('PENDING_APPROVAL');
            $table->unsignedInteger('version')->default(0);
            $table->string('reason', 500)->nullable();
            $table->json('bank_snapshot');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'wallet_scope', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
        // A withdrawal reserves slices of payables; releasing a slice returns it to the available balance.
        Schema::create('withdrawal_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained('business_withdrawals')->restrictOnDelete();
            $table->foreignId('payable_id')->constrained('business_payables')->restrictOnDelete();
            $table->unsignedBigInteger('amount_vnd');
            $table->boolean('released')->default(false);
            $table->timestamps();
            $table->unique(['withdrawal_id', 'payable_id']);
        });
        Schema::create('withdrawal_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained('business_withdrawals')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('provider', 20);
            $table->enum('status', ['SUCCESS', 'FAILED']);
            $table->string('reference', 120)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();
            $table->unique(['withdrawal_id', 'sequence']);
        });
        // Batch settlement (SimSettlement): a platform admin gathers a business's unallocated payables into one record and
        // marks it paid. Nothing here moves real money; the paid state is backed by one SETTLEMENT ledger entry.
        Schema::create('sim_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->enum('wallet_scope', ['DEMO', 'LIVE_LAB'])->default('LIVE_LAB');
            $table->string('operation_key', 150)->unique();
            $table->char('fingerprint', 64);
            $table->enum('status', ['PENDING', 'SIMULATED_PAID'])->default('PENDING');
            $table->unsignedBigInteger('amount_vnd');
            $table->json('bank_snapshot');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });
        Schema::table('business_payables', function (Blueprint $table) {
            $table->foreign('settlement_id')->references('id')->on('sim_settlements')->restrictOnDelete();
        });
        Schema::create('withdrawal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained('business_withdrawals')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Payout history is financial evidence: roll back code first and drop these only on an empty environment.
        Schema::table('business_payables', fn (Blueprint $table) => $table->dropForeign(['settlement_id']));
        foreach (['withdrawal_events', 'withdrawal_attempts', 'withdrawal_allocations', 'business_withdrawals', 'business_bank_accounts', 'sim_settlements', 'business_payables'] as $table) Schema::dropIfExists($table);
    }
};
