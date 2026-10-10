<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_policies', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('business_service_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name', 160); $t->unsignedInteger('version'); $t->string('status')->default('ACTIVE');
            $t->enum('deposit_type', ['NONE','FIXED','PERCENTAGE','FULL_PREPAYMENT']); $t->unsignedBigInteger('deposit_value_cents')->default(0);
            $t->boolean('allow_split_payment')->default(true); $t->boolean('allow_installments')->default(false);
            $t->dateTime('effective_from'); $t->dateTime('effective_to')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('payment_policy_snapshots', function (Blueprint $t) {
            $t->id(); $t->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $t->json('snapshot'); $t->unsignedBigInteger('required_amount_cents'); $t->timestamps();
        });
        Schema::create('treatment_packages', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->restrictOnDelete(); $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name',160); $t->text('description')->nullable(); $t->unsignedBigInteger('total_cents'); $t->char('currency',3)->default('VND');
            $t->unsignedSmallInteger('session_count'); $t->unsignedSmallInteger('validity_days'); $t->string('status')->default('ACTIVE'); $t->timestamps();
        });
        Schema::create('package_purchases', function (Blueprint $t) {
            $t->id(); $t->foreignId('treatment_package_id')->constrained()->restrictOnDelete(); $t->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $t->foreignId('business_id')->constrained()->restrictOnDelete(); $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('total_cents'); $t->unsignedBigInteger('paid_cents')->default(0); $t->char('currency',3)->default('VND');
            $t->string('status')->default('PENDING_PAYMENT'); $t->json('pricing_snapshot'); $t->dateTime('expires_at'); $t->timestamps();
        });
        Schema::create('package_installments', function (Blueprint $t) {
            $t->id(); $t->foreignId('package_purchase_id')->constrained()->restrictOnDelete(); $t->unsignedTinyInteger('sequence');
            $t->unsignedBigInteger('amount_cents'); $t->dateTime('due_at'); $t->string('status')->default('DUE'); $t->dateTime('paid_at')->nullable(); $t->timestamps();
            $t->unique(['package_purchase_id','sequence'], 'package_installment_sequence_unique');
        });
        Schema::create('package_session_entitlements', function (Blueprint $t) {
            $t->id(); $t->foreignId('package_purchase_id')->constrained()->restrictOnDelete(); $t->unsignedSmallInteger('sequence');
            $t->string('status')->default('AVAILABLE'); $t->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('booking_service_id')->nullable()->unique()->constrained()->restrictOnDelete(); $t->dateTime('reserved_at')->nullable(); $t->dateTime('redeemed_at')->nullable(); $t->timestamps();
            $t->unique(['package_purchase_id','sequence'], 'package_entitlement_sequence_unique');
        });
        Schema::table('payment_transactions', function (Blueprint $t) {
            $t->unsignedBigInteger('booking_id')->nullable()->change();
            $t->foreignId('package_purchase_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('package_installment_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('payment_ledger_entries', function (Blueprint $t) {
            $t->unsignedBigInteger('booking_id')->nullable()->change(); $t->unsignedBigInteger('payment_transaction_id')->nullable()->change();
            $t->foreignId('package_purchase_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type')->change();
        });
        Schema::create('platform_fee_entries', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->restrictOnDelete(); $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_id')->unique()->constrained()->restrictOnDelete(); $t->unsignedBigInteger('base_cents');
            $t->unsignedSmallInteger('fee_rate_basis_points'); $t->unsignedBigInteger('fee_cents'); $t->json('calculation_snapshot'); $t->string('status')->default('OPEN'); $t->timestamps();
        });
        Schema::create('platform_fee_adjustments', function (Blueprint $t) {
            $t->id(); $t->foreignId('platform_fee_entry_id')->constrained()->restrictOnDelete(); $t->foreignId('refund_request_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_cents'); $t->text('reason'); $t->timestamps();
        });
        Schema::create('platform_statements', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->restrictOnDelete(); $t->date('period_start'); $t->date('period_end');
            $t->string('status')->default('DRAFT'); $t->unsignedInteger('version')->default(1); $t->unsignedBigInteger('gross_fee_cents'); $t->unsignedBigInteger('adjustment_cents'); $t->bigInteger('net_cents');
            $t->json('lines'); $t->dateTime('issued_at')->nullable(); $t->dateTime('locked_at')->nullable(); $t->dateTime('paid_at')->nullable(); $t->timestamps();
            $t->unique(['business_id','period_start','period_end','version'], 'platform_statement_period_version_unique');
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->constrained()->restrictOnDelete(); $t->foreignId('booking_id')->constrained()->restrictOnDelete();
            $t->string('invoice_number',40); $t->string('status')->default('ISSUED'); $t->unsignedInteger('version')->default(1); $t->timestamp('cancelled_at')->nullable(); $t->unsignedBigInteger('subtotal_cents'); $t->unsignedBigInteger('discount_cents')->default(0);
            $t->unsignedBigInteger('tax_cents'); $t->unsignedBigInteger('total_cents'); $t->unsignedSmallInteger('tax_rate_basis_points'); $t->boolean('tax_inclusive');
            $t->json('buyer_snapshot'); $t->json('seller_snapshot'); $t->json('lines'); $t->foreignId('replaces_invoice_id')->nullable()->unique()->constrained('invoices')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamp('issued_at'); $t->timestamps();
            $t->unique(['business_id','invoice_number']);
        });
        Schema::create('invoice_information_requests', function (Blueprint $t) {
            $t->id(); $t->foreignId('booking_id')->constrained()->restrictOnDelete(); $t->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->nullable()->unique()->constrained()->restrictOnDelete(); $t->string('status')->default('PENDING'); $t->json('buyer_snapshot');
            $t->text('note')->nullable(); $t->text('resolution_note')->nullable(); $t->unsignedBigInteger('open_key')->nullable()->unique(); $t->string('idempotency_key',200)->unique();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete(); $t->dateTime('resolved_at')->nullable(); $t->timestamps();
        });
        Schema::create('invoice_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('invoice_id')->constrained()->restrictOnDelete(); $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action'); $t->text('reason')->nullable(); $t->json('snapshot'); $t->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void
    {
        foreach (['invoice_events','invoice_information_requests','invoices','platform_statements','platform_fee_adjustments','platform_fee_entries'] as $table) Schema::dropIfExists($table);
        Schema::table('payment_ledger_entries', function (Blueprint $t) { $t->dropConstrainedForeignId('package_purchase_id'); });
        Schema::table('payment_transactions', function (Blueprint $t) { $t->dropConstrainedForeignId('package_installment_id'); $t->dropConstrainedForeignId('package_purchase_id'); });
        foreach (['package_session_entitlements','package_installments','package_purchases','treatment_packages','payment_policy_snapshots','payment_policies'] as $table) Schema::dropIfExists($table);
    }
};
