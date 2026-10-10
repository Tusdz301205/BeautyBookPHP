<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', fn (Blueprint $table) => $table->date('date_of_birth')->nullable());
        foreach (['promotions', 'vouchers'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained()->restrictOnDelete();
                $table->boolean('created_by_platform')->default(false);
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('discount_type', 24);
                $table->decimal('discount_value', 12, 2);
                $table->dateTime('start_date');
                $table->dateTime('end_date');
                $table->string('status', 24)->default('ACTIVE');
                $table->string('audience', 24)->default('ALL');
                $table->unsignedInteger('total_quantity')->nullable();
                $table->unsignedInteger('max_usage_per_customer')->default(1);
                $table->unsignedInteger('version')->default(1);
                $table->json('branch_ids')->nullable();
                $table->json('service_ids')->nullable();
                $table->json('combo_ids')->nullable();
                if ($name === 'promotions') {
                    $table->boolean('auto_apply')->default(true);
                    $table->boolean('stacking_allowed')->default(false);
                } else {
                    $table->string('code', 64)->unique();
                    $table->string('scope', 24)->default('TENANT');
                    $table->decimal('min_order_value', 12, 2)->default(0);
                    $table->decimal('max_discount', 12, 2)->nullable();
                    $table->unsignedInteger('used_quantity')->default(0);
                    $table->boolean('auto_issue')->default(false);
                }
                $table->timestamps();
                $table->softDeletes();
                $table->index(['business_id', 'status']);
            });
        }
        Schema::create('customer_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('ACTIVE');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['voucher_id', 'customer_profile_id']);
        });
        Schema::create('customer_business_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->string('segment', 24);
            $table->timestamps();
            $table->unique(['business_id', 'customer_profile_id', 'segment'], 'customer_segment_unique');
        });
        foreach (['promotion_redemptions' => 'promotion_id', 'voucher_redemptions' => 'voucher_id'] as $name => $source) {
            Schema::create($name, function (Blueprint $table) use ($source) {
                $table->id();
                $table->foreignId($source)->constrained()->restrictOnDelete();
                $table->foreignId('booking_id')->constrained()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->json('rule_snapshot');
                $table->string('status', 24)->default('RESERVED');
                $table->dateTime('applied_at')->nullable();
                $table->dateTime('released_at')->nullable();
                $table->timestamps();
                $table->unique([$source, 'booking_id']);
                $table->index([$source, 'customer_profile_id', 'status'], $source.'_usage_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['voucher_redemptions', 'promotion_redemptions', 'customer_business_segments', 'customer_vouchers', 'vouchers', 'promotions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('customer_profiles', fn (Blueprint $table) => $table->dropColumn('date_of_birth'));
    }
};
