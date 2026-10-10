<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('combos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('combo_price', 14, 2);
            $table->string('pricing_mode')->default('FIXED_PRICE');
            $table->string('staff_assignment_mode')->default('SINGLE_PROVIDER');
            $table->string('status')->default('ACTIVE');
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_to')->nullable();
            $table->unsignedInteger('max_usage')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'status']);
        });
        Schema::create('combo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_service_offering_id')->constrained();
            $table->unsignedTinyInteger('quantity');
            $table->unsignedTinyInteger('sort_order');
            $table->unsignedSmallInteger('transition_minutes')->default(0);
            $table->decimal('price_snapshot', 14, 2);
            $table->unsignedSmallInteger('duration_snapshot');
            $table->timestamps();
            $table->unique(['combo_id', 'branch_service_offering_id'], 'combo_offering_unique');
            $table->unique(['combo_id', 'sort_order']);
        });
        Schema::create('recurring_booking_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->string('frequency');
            $table->string('staff_mode');
            $table->string('status')->default('CREATING');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('preferred_time', 5);
            $table->unsignedTinyInteger('occurrence_count');
            $table->json('request_snapshot');
            $table->timestamps();
            $table->unique(['customer_profile_id', 'idempotency_key'], 'recurring_customer_key_unique');
        });
        Schema::create('recurring_booking_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_booking_series_id')->constrained('recurring_booking_series', indexName: 'recurring_occurrence_series_fk')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained();
            $table->unsignedTinyInteger('sequence');
            $table->dateTime('scheduled_start_at');
            $table->string('status')->default('BOOKED');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['recurring_booking_series_id', 'sequence'], 'recurring_sequence_unique');
            $table->unique('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_booking_occurrences');
        Schema::dropIfExists('recurring_booking_series');
        Schema::dropIfExists('combo_items');
        Schema::dropIfExists('combos');
    }
};
