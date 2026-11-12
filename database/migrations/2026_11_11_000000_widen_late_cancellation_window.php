<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two windows of the violation-evidence constraint were fixed in the schema, and both now belong to the branch policy:
 * a late cancellation was recorded only inside a fixed 4 hours before the appointment (the branch's own cancellation window
 * is 0-168 hours), and a no-show only after a fixed 15 minutes (the branch's own grace period is 0-1440 minutes). The
 * constraint therefore accepts the whole range; which exact hour or minute counts is decided by the application from the
 * branch policy at the time of the request or of the mark.
 */
return new class extends Migration
{
    private function constraint(int $hours, int $graceMinutes): string
    {
        return "ALTER TABLE booking_violation_events ADD CONSTRAINT chk_booking_violation_source_time CHECK ((kind = 'LATE_CANCELLATION' AND source_request_id IS NOT NULL AND occurred_at < appointment_start_at AND occurred_at > DATE_SUB(appointment_start_at, INTERVAL {$hours} HOUR)) OR (kind = 'NO_SHOW' AND source_request_id IS NULL AND occurred_at > DATE_ADD(appointment_start_at, INTERVAL {$graceMinutes} MINUTE)))";
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;
        DB::statement('ALTER TABLE booking_violation_events DROP CHECK chk_booking_violation_source_time');
        DB::statement($this->constraint(168, 0));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;
        // Events recorded under a longer window or a shorter grace would violate the old constraint, so the rollback refuses to run over them.
        DB::statement('ALTER TABLE booking_violation_events DROP CHECK chk_booking_violation_source_time');
        DB::statement($this->constraint(4, 15));
    }
};
