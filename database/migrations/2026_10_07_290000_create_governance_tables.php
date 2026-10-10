<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_active')->default(true)->index());
        Schema::table('booking_status_histories', fn (Blueprint $table) => $table->string('actor_kind', 24)->nullable());
        Schema::create('trust_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('total_bookings');
            $table->decimal('cancellation_rate', 8, 5);
            $table->decimal('no_show_rate', 8, 5);
            $table->decimal('late_cancel_by_salon_rate', 8, 5);
            $table->decimal('avg_confirmation_time_minutes', 12, 2);
            $table->unsignedTinyInteger('trust_score');
            $table->string('alert_level', 12);
            $table->json('reasons');
            $table->timestamp('computed_at');
            $table->timestamps();
        });
        Schema::create('trust_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->text('reason');
            $table->text('internal_note')->nullable();
            $table->string('status_before', 40)->nullable();
            $table->string('status_after', 40)->nullable();
            $table->string('outcome', 24)->default('APPLIED');
            $table->foreignId('restore_of_action_id')->nullable()->constrained('trust_actions')->restrictOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_actions');
        Schema::dropIfExists('trust_snapshots');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('booking_status_histories', fn (Blueprint $table) => $table->dropColumn('actor_kind'));
    }
};
