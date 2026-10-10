<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE booking_services MODIFY status ENUM('SCHEDULED','IN_PROGRESS','COMPLETED','CANCELLED','SKIPPED') NOT NULL DEFAULT 'SCHEDULED'");
        Schema::table('booking_services', function (Blueprint $t) {
            $t->timestamp('actual_started_at')->nullable();
            $t->timestamp('actual_completed_at')->nullable();
            $t->string('actual_timing_status', 20)->default('UNKNOWN');
        });
        Schema::create('beautybook_booking_edits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_service_id')->nullable()->constrained('booking_services')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->text('reason')->nullable();
            $t->json('before');
            $t->json('after');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beautybook_booking_edits');
        Schema::table('booking_services', fn (Blueprint $t) => $t->dropColumn(['actual_started_at', 'actual_completed_at', 'actual_timing_status']));
    }
};
