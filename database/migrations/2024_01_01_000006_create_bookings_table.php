<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 20)->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->dateTime('appointment_date');
            $table->unsignedSmallInteger('total_duration_minutes')->default(0);
            $table->unsignedInteger('total_price')->default(0);
            $table->enum('status', [
                'pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'
            ])->default('pending');
            $table->enum('cancelled_by', ['customer', 'owner', 'system'])->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['appointment_date']);
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('service_name'); // snapshot at booking time
            $table->unsignedInteger('price'); // snapshot at booking time
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestamps();
        });

        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('bookings');
    }
};
