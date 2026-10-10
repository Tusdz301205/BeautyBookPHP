<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('VND');
            $table->enum('method', ['CASH', 'BANK_TRANSFER']);
            $table->enum('status', ['PENDING', 'VERIFIED', 'REVERSED']);
            $table->string('idempotency_key', 200)->unique();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('payment_transactions')->restrictOnDelete();
            $table->string('settlement_reference', 200)->nullable()->unique();
            $table->json('evidence')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['booking_id', 'status']);
        });
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->text('reason');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'PROCESSING', 'REFUNDED', 'FAILED'])->default('PENDING');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_note')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('settlement_reference', 200)->nullable()->unique();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('refund_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('type', ['PAYMENT_RECEIVED', 'REVERSAL', 'REFUND']);
            $table->enum('direction', ['CREDIT', 'DEBIT']);
            $table->unsignedBigInteger('amount_cents');
            $table->string('idempotency_key', 200)->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('payment_ledger_entries');
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('payment_transactions');
    }
};
