<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('address')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('customer_profile_id')->nullable()->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('booking_code', 40)->unique();
            $table->dateTime('appointment_start_at', 6);
            $table->dateTime('appointment_end_at', 6);
            $table->enum('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'NO_SHOW', 'REJECTED', 'EXPIRED'])->default('PENDING');
            $table->enum('source', ['ONLINE_WEB', 'ONLINE_APP', 'WALK_IN', 'PHONE', 'STAFF_CREATED', 'ADMIN_CREATED'])->default('ONLINE_WEB');
            $table->dateTime('pending_expires_at', 6)->nullable();
            $table->char('currency', 3)->default('VND');
            $table->decimal('total_amount', 14, 2);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['branch_id', 'business_id'], 'bookings_branch_business_fk')
                ->references(['id', 'business_id'])->on('branches')->restrictOnDelete();
            $table->unique(['id', 'business_id', 'branch_id'], 'bookings_id_business_branch_unique');
            $table->index(['branch_id', 'status', 'appointment_start_at'], 'bookings_branch_status_start_index');
            $table->index(['customer_profile_id', 'status', 'appointment_start_at'], 'bookings_customer_status_start_index');
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT chk_bookings_time_order CHECK (appointment_start_at < appointment_end_at)');

        Schema::create('booking_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->string('full_name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE booking_contacts ADD CONSTRAINT chk_booking_contacts_channel CHECK (phone IS NOT NULL OR email IS NOT NULL)");

        Schema::create('booking_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('branch_service_offering_id');
            $table->unsignedBigInteger('business_service_id');
            $table->unsignedBigInteger('canonical_service_id')->nullable();
            $table->unsignedBigInteger('staff_profile_id')->nullable();
            $table->unsignedBigInteger('service_variant_id')->nullable();
            $table->decimal('price_at_booking', 14, 2);
            $table->char('currency', 3)->default('VND');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->string('service_name_snapshot', 160);
            $table->string('variant_name_snapshot', 160)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->enum('status', ['SCHEDULED', 'COMPLETED', 'CANCELLED'])->default('SCHEDULED');
            $table->dateTime('item_start_at', 6);
            $table->dateTime('item_end_at', 6);
            $table->dateTime('occupied_start_at', 6);
            $table->dateTime('occupied_end_at', 6);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->foreign(['booking_id', 'business_id', 'branch_id'], 'booking_services_booking_scope_fk')
                ->references(['id', 'business_id', 'branch_id'])->on('bookings')->restrictOnDelete();
            $table->foreign(['branch_service_offering_id', 'business_id', 'branch_id'], 'booking_services_offering_scope_fk')
                ->references(['id', 'business_id', 'branch_id'])->on('branch_service_offerings')->restrictOnDelete();
            $table->foreign(['business_service_id', 'business_id'], 'booking_services_service_business_fk')
                ->references(['id', 'business_id'])->on('business_services')->restrictOnDelete();
            $table->foreign('canonical_service_id')->references('id')->on('canonical_services')->restrictOnDelete();
            $table->foreign(['staff_profile_id', 'business_id', 'branch_id'], 'booking_services_staff_scope_fk')
                ->references(['id', 'business_id', 'branch_id'])->on('staff_profiles')->restrictOnDelete();
            $table->foreign(['service_variant_id', 'branch_service_offering_id'], 'booking_services_variant_offering_fk')
                ->references(['id', 'branch_service_offering_id'])->on('service_variants')->restrictOnDelete();
            $table->index(['branch_id', 'occupied_start_at', 'occupied_end_at'], 'booking_services_branch_window_index');
            $table->index(['staff_profile_id', 'occupied_start_at', 'occupied_end_at'], 'booking_services_staff_window_index');
        });

        DB::statement('ALTER TABLE booking_services ADD CONSTRAINT chk_booking_services_item_time CHECK (item_start_at < item_end_at)');
        DB::statement('ALTER TABLE booking_services ADD CONSTRAINT chk_booking_services_occupied_time CHECK (occupied_start_at <= item_start_at AND occupied_end_at >= item_end_at AND occupied_start_at < occupied_end_at)');

        Schema::create('booking_pricing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->char('currency', 3)->default('VND');
            $table->decimal('subtotal_amount', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('final_amount', 14, 2);
            $table->string('quote_version', 40)->default('booking-v1');
            $table->json('source_snapshot');
            $table->timestamps();
        });

        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['booking_id', 'created_at']);
        });

        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('workspace_key', 100);
            $table->string('idempotency_key', 80);
            $table->char('payload_hash', 64);
            $table->enum('status', ['PROCESSING', 'COMPLETED'])->default('PROCESSING');
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();
            $table->unique(['actor_user_id', 'workspace_key', 'idempotency_key'], 'idempotency_actor_workspace_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('booking_pricing_snapshots');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('booking_contacts');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('customer_profiles');
    }
};
