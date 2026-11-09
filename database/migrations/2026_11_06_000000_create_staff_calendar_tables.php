<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->unsignedSmallInteger('start_minute')->nullable();
            $table->unsignedSmallInteger('end_minute')->nullable();
            $table->boolean('is_off');
            $table->unique(['staff_profile_id', 'day_of_week', 'start_minute'], 'staff_hours_day_start');
        });

        Schema::create('staff_time_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at', 3);
            $table->dateTime('ends_at', 3);
            $table->string('reason', 500)->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['staff_profile_id', 'starts_at', 'ends_at'], 'staff_time_off_window');
        });

        DB::statement('ALTER TABLE staff_working_hours ADD CONSTRAINT staff_hours_shape CHECK (
            day_of_week BETWEEN 0 AND 6 AND (
                (is_off = 1 AND start_minute IS NULL AND end_minute IS NULL)
                OR (is_off = 0 AND start_minute IS NOT NULL AND end_minute IS NOT NULL
                    AND start_minute < end_minute AND end_minute <= 1440)
            )
        )');
        DB::statement('ALTER TABLE staff_time_offs ADD CONSTRAINT staff_time_off_positive CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_time_offs');
        Schema::dropIfExists('staff_working_hours');
    }
};
