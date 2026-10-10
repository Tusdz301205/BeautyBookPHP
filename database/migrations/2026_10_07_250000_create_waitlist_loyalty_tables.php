<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_service_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('window_start');
            $table->dateTime('window_end');
            $table->string('status', 24)->default('WAITING');
            $table->string('active_join_key', 100)->nullable()->unique();
            $table->string('offer_slot_key', 120)->nullable()->unique();
            $table->string('offer_token_hash', 64)->nullable();
            $table->dateTime('offered_start_at')->nullable();
            $table->dateTime('offer_expires_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['customer_profile_id', 'status']);
            $table->index(['branch_id', 'status']);
        });
        Schema::create('loyalty_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('earn_points_per_amount');
            $table->decimal('earn_amount_unit', 12, 2);
            $table->decimal('redemption_value_per_point', 12, 2);
            $table->unsignedInteger('expires_after_days')->nullable();
            $table->boolean('active')->default(true);
            $table->dateTime('valid_from');
            $table->dateTime('valid_to')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'branch_id', 'active']);
        });
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['business_id', 'customer_profile_id']);
        });
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->bigInteger('points');
            $table->unsignedBigInteger('balance_after');
            $table->string('idempotency_key', 160)->unique();
            $table->json('rule_snapshot')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('loyalty_transactions')->restrictOnDelete();
            $table->dateTime('expires_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['loyalty_account_id', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['loyalty_transactions', 'loyalty_accounts', 'loyalty_rules', 'waitlist_entries'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
