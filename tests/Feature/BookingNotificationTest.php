<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Booking\BookingNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class BookingNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_notification_and_outbox_are_deduplicated_together(): void
    {
        $user = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $service = app(BookingNotificationService::class);
        $dedupeKey = 'notification-test:dedupe';

        $service->record(
            $user->id,
            'BOOKING_STATUS_CHANGED',
            'Trạng thái lịch hẹn đã thay đổi',
            'Nội dung thử nghiệm',
            null,
            $dedupeKey,
            'INFO',
            ['status' => 'CONFIRMED'],
        );
        $service->record(
            $user->id,
            'BOOKING_STATUS_CHANGED',
            'Trạng thái lịch hẹn đã thay đổi',
            'Nội dung thử nghiệm lần hai',
            null,
            $dedupeKey,
            'WARNING',
            ['status' => 'CANCELLED'],
        );

        $notification = DB::table('notifications')->where('dedupe_key', $dedupeKey)->first();
        $outbox = DB::table('notification_outbox')->where('dedupe_key', $dedupeKey)->first();

        $this->assertNotNull($notification);
        $this->assertNotNull($outbox);
        $this->assertSame(1, DB::table('notifications')->where('dedupe_key', $dedupeKey)->count());
        $this->assertSame(1, DB::table('notification_outbox')->where('dedupe_key', $dedupeKey)->count());
        $this->assertSame((int) $notification->id, (int) $outbox->notification_id);
        $this->assertSame('INFO', $notification->severity);
        $this->assertSame('PENDING', $outbox->status);
    }

    public function test_notification_failure_rolls_back_booking_transition_history_and_outbox(): void
    {
        $booking = $this->createCustomerBooking('notification-rollback');
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $historyCount = DB::table('booking_status_histories')->where('booking_id', $booking->id)->count();
        $notificationCount = DB::table('notifications')->where('related_booking_id', $booking->id)->count();
        $outboxCount = DB::table('notification_outbox')->where('related_booking_id', $booking->id)->count();

        $failingNotifications = new class extends BookingNotificationService
        {
            public function record(
                int $userId,
                string $type,
                string $title,
                ?string $body,
                ?int $bookingId,
                string $dedupeKey,
                string $severity = 'INFO',
                array $metadata = [],
            ): void {
                parent::record($userId, $type, $title, $body, $bookingId, $dedupeKey, $severity, $metadata);
                throw new RuntimeException('notification-write-failure');
            }
        };
        $this->app->instance(BookingNotificationService::class, $failingNotifications);

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->withoutExceptionHandling();

        try {
            $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'CONFIRMED']);
            $this->fail('Expected notification failure to abort the transition transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('notification-write-failure', $exception->getMessage());
        }

        $this->assertSame('PENDING', $booking->fresh()->status);
        $this->assertSame($historyCount, DB::table('booking_status_histories')->where('booking_id', $booking->id)->count());
        $this->assertSame($notificationCount, DB::table('notifications')->where('related_booking_id', $booking->id)->count());
        $this->assertSame($outboxCount, DB::table('notification_outbox')->where('related_booking_id', $booking->id)->count());
    }

    private function createCustomerBooking(string $idempotencyKey): Booking
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();

        $this->actingAs($customer);
        $response = $this->postJsonWithCsrf(route('booking.store', $branch), [
            'branch_service_offering_id' => $offering->id,
            'service_variant_id' => null,
            'staff_profile_id' => $staff->id,
            'start_at' => $this->openStart(),
        ], $idempotencyKey)->assertCreated();

        return Booking::query()->findOrFail($response->json('booking_id'));
    }

    private function postJsonWithCsrf(string $uri, array $data, ?string $idempotencyKey = null): TestResponse
    {
        $token = 'beautybook-notification-test-csrf';
        $headers = ['X-CSRF-TOKEN' => $token];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->withSession(['_token' => $token])->withHeaders($headers)->postJson($uri, $data);
    }

    private function openStart(): string
    {
        $time = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTime(10, 0);
        while ($time->dayOfWeek === 0) {
            $time = $time->addDay();
        }

        return $time->format('Y-m-d\TH:i');
    }
}
