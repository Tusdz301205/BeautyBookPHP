<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE booking_services MODIFY status ENUM('SCHEDULED','IN_PROGRESS','COMPLETED','CANCELLED') NOT NULL DEFAULT 'SCHEDULED'");

        Schema::create('appointment_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('requested_by_type', ['CUSTOMER', 'SALON', 'ADMIN']);
            $table->enum('request_type', ['RESCHEDULE', 'STAFF_CHANGE', 'CANCEL']);
            $table->dateTime('proposed_start_at', 6)->nullable();
            $table->dateTime('proposed_end_at', 6)->nullable();
            $table->foreignId('proposed_staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'EXPIRED'])->default('PENDING');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->text('review_note')->nullable();
            $table->dateTime('expires_at', 6);
            $table->timestamps();
            $table->index(['booking_id', 'status'], 'appointment_change_requests_booking_status_index');
        });

        Schema::create('booking_violation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_request_id')->nullable()->unique()->constrained('appointment_change_requests')->restrictOnDelete();
            $table->enum('kind', ['LATE_CANCELLATION', 'NO_SHOW']);
            $table->dateTime('occurred_at', 6);
            $table->dateTime('appointment_start_at', 6);
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('policy_version', 64);
            $table->dateTime('voided_at', 6)->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['booking_id', 'voided_at'], 'booking_violation_events_booking_voided_index');
            $table->index(['customer_profile_id', 'business_id', 'occurred_at'], 'booking_violation_events_policy_window_index');
        });

        DB::statement("ALTER TABLE booking_violation_events ADD COLUMN valid_booking_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(voided_at IS NULL, booking_id, NULL)) STORED, ADD UNIQUE KEY booking_violation_one_valid_event (valid_booking_id)");
        DB::statement("ALTER TABLE booking_violation_events ADD CONSTRAINT chk_booking_violation_source_time CHECK ((kind = 'LATE_CANCELLATION' AND source_request_id IS NOT NULL AND occurred_at < appointment_start_at AND occurred_at > DATE_SUB(appointment_start_at, INTERVAL 4 HOUR)) OR (kind = 'NO_SHOW' AND source_request_id IS NULL AND occurred_at > DATE_ADD(appointment_start_at, INTERVAL 15 MINUTE)))");
        DB::statement("ALTER TABLE booking_violation_events ADD CONSTRAINT chk_booking_violation_void CHECK ((voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND CHAR_LENGTH(TRIM(void_reason)) >= 3))");
        DB::unprepared(<<<'SQL'
CREATE TRIGGER booking_violation_source_valid
BEFORE INSERT ON booking_violation_events
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM bookings b
        WHERE b.id = NEW.booking_id
          AND b.customer_profile_id = NEW.customer_profile_id
          AND b.business_id = NEW.business_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Violation scope does not match booking';
    END IF;

    IF NEW.kind = 'LATE_CANCELLATION' AND NOT EXISTS (
        SELECT 1
        FROM appointment_change_requests r
        INNER JOIN customer_profiles c ON c.id = NEW.customer_profile_id
        WHERE r.id = NEW.source_request_id
          AND r.booking_id = NEW.booking_id
          AND r.request_type = 'CANCEL'
          AND r.requested_by_type = 'CUSTOMER'
          AND r.requested_by_user_id = c.user_id
          AND NEW.recorded_by_user_id = c.user_id
          AND r.created_at = NEW.occurred_at
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid late cancellation source';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER booking_violation_evidence_immutable
BEFORE UPDATE ON booking_violation_events
FOR EACH ROW
BEGIN
    IF NOT (NEW.booking_id <=> OLD.booking_id)
        OR NOT (NEW.customer_profile_id <=> OLD.customer_profile_id)
        OR NOT (NEW.business_id <=> OLD.business_id)
        OR NOT (NEW.source_request_id <=> OLD.source_request_id)
        OR NOT (NEW.kind <=> OLD.kind)
        OR NOT (NEW.occurred_at <=> OLD.occurred_at)
        OR NOT (NEW.appointment_start_at <=> OLD.appointment_start_at)
        OR NOT (NEW.recorded_by_user_id <=> OLD.recorded_by_user_id)
        OR NOT (NEW.policy_version <=> OLD.policy_version)
        OR NOT (NEW.created_at <=> OLD.created_at)
        OR OLD.voided_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Violation evidence is immutable';
    END IF;
END
SQL);

        Schema::create('customer_booking_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->dateTime('starts_at', 6)->nullable();
            $table->dateTime('ends_at', 6)->nullable();
            $table->unsignedBigInteger('triggered_by_violation_event_id')->nullable();
            $table->unique('triggered_by_violation_event_id', 'cbp_trigger_violation_unique');
            $table->foreign('triggered_by_violation_event_id', 'cbp_trigger_violation_fk')
                ->references('id')
                ->on('booking_violation_events')
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(['customer_profile_id', 'business_id'], 'customer_booking_policies_customer_business_unique');
            $table->index(['business_id', 'ends_at'], 'customer_booking_policies_business_ends_index');
        });
        DB::statement("ALTER TABLE customer_booking_policies ADD CONSTRAINT chk_customer_booking_policy_dates CHECK ((starts_at IS NULL AND ends_at IS NULL AND triggered_by_violation_event_id IS NULL) OR (starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at > starts_at AND triggered_by_violation_event_id IS NOT NULL))");
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_booking_policy_scope_insert
BEFORE INSERT ON customer_booking_policies
FOR EACH ROW
BEGIN
    IF NEW.triggered_by_violation_event_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM booking_violation_events e
        WHERE e.id = NEW.triggered_by_violation_event_id
          AND e.customer_profile_id = NEW.customer_profile_id
          AND e.business_id = NEW.business_id
          AND e.voided_at IS NULL
          AND NEW.ends_at = DATE_ADD(e.occurred_at, INTERVAL 30 DAY)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Restriction requires a valid same-scope event and a 30-day end date';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_booking_policy_scope_update
BEFORE UPDATE ON customer_booking_policies
FOR EACH ROW
BEGIN
    IF NEW.triggered_by_violation_event_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM booking_violation_events e
        WHERE e.id = NEW.triggered_by_violation_event_id
          AND e.customer_profile_id = NEW.customer_profile_id
          AND e.business_id = NEW.business_id
          AND e.voided_at IS NULL
          AND NEW.ends_at = DATE_ADD(e.occurred_at, INTERVAL 30 DAY)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Restriction requires a valid same-scope event and a 30-day end date';
    END IF;
END
SQL);

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 64);
            $table->enum('severity', ['INFO', 'WARNING', 'CRITICAL'])->default('INFO');
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('read_at', 6)->nullable();
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('action_url')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('related_booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->string('dedupe_key', 191)->unique();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['user_id', 'is_read'], 'notifications_user_read_index');
        });

        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('notification_id')->unique()->constrained('notifications')->restrictOnDelete();
            $table->foreignId('related_booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->string('dedupe_key', 191)->unique();
            $table->enum('status', ['PENDING', 'SENT', 'FAILED'])->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at', 6)->useCurrent();
            $table->text('last_error')->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->json('payload');
            $table->timestamps();
            $table->index(['status', 'available_at'], 'notification_outbox_status_available_index');
            $table->index(['user_id', 'created_at'], 'notification_outbox_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('customer_booking_policies');
        Schema::dropIfExists('booking_violation_events');
        Schema::dropIfExists('appointment_change_requests');
        DB::statement("ALTER TABLE booking_services MODIFY status ENUM('SCHEDULED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'SCHEDULED'");
    }
};
