<?php

namespace Tests\Feature;

use App\Exceptions\BookingConflictException;
use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\CustomerBookingPolicy;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Booking\BookingPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BookingPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_score_three_requires_ack_score_four_restricts_only_same_business(): void
    {
        $service = app(BookingPolicyService::class);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $profile = CustomerProfile::where('user_id', $customer->id)->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $sibling = Branch::where('slug', 'binh-tri-dong')->firstOrFail();
        $other = Branch::where('slug', 'vo-van-tan')->firstOrFail();
        $base = CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC');

        $this->recordNoShow($service, $branch, $profile, $customer, $owner, $base, 'score3-no-show');
        $this->recordLateCancellation($service, $sibling, $profile, $customer, $base->addHour(), 'score3-late');

        $scoreThree = $service->read($profile->id, $branch->business_id, $base->addHours(2));
        $this->assertSame(3, $scoreThree['score']);
        $this->assertTrue($scoreThree['acknowledgment_required']);
        $this->assertTrue($scoreThree['self_booking_allowed']);

        $beforeRead = CustomerBookingPolicy::query()
            ->where('customer_profile_id', $profile->id)
            ->where('business_id', $branch->business_id)
            ->firstOrFail();
        $beforeRevision = $beforeRead->revision;
        $beforeUpdatedAt = $beforeRead->getRawOriginal('updated_at');

        $service->read($profile->id, $branch->business_id, $base->addHours(2));
        $afterRead = $beforeRead->fresh();
        $this->assertSame($beforeRevision, $afterRead->revision);
        $this->assertSame($beforeUpdatedAt, $afterRead->getRawOriginal('updated_at'));

        try {
            DB::transaction(fn () => $service->assertSelfBookingAllowed(
                $profile->id,
                $branch->business_id,
                false,
                $base->addHours(2),
            ));
            $this->fail('Score 3 phải yêu cầu xác nhận cảnh báo.');
        } catch (BookingConflictException) {
            $this->assertTrue(true);
        }

        $acknowledged = DB::transaction(fn () => $service->assertSelfBookingAllowed(
            $profile->id,
            $branch->business_id,
            true,
            $base->addHours(2),
        ));
        $this->assertSame(3, $acknowledged['score']);

        $trigger = $this->recordLateCancellation(
            $service,
            $branch,
            $profile,
            $customer,
            $base->addHours(3),
            'score4-late',
        );
        $restricted = $service->read($profile->id, $branch->business_id, $base->addHours(4));
        $this->assertSame(4, $restricted['score']);
        $this->assertFalse($restricted['self_booking_allowed']);
        $this->assertTrue($restricted['restriction']['active']);
        $this->assertSame(
            CarbonImmutable::parse($trigger->getRawOriginal('occurred_at'), 'UTC')->addDays(30)->toIso8601String(),
            $restricted['restriction']['ends_at'],
        );

        try {
            DB::transaction(fn () => $service->assertSelfBookingAllowed(
                $profile->id,
                $branch->business_id,
                true,
                $base->addHours(4),
            ));
            $this->fail('Restriction đang hoạt động phải chặn self-booking.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $otherPolicy = $service->read($profile->id, $other->business_id, $base->addHours(4));
        $this->assertSame(0, $otherPolicy['score']);
        $this->assertTrue($otherPolicy['self_booking_allowed']);
        $this->assertDatabaseMissing('customer_booking_policies', [
            'customer_profile_id' => $profile->id,
            'business_id' => $other->business_id,
        ]);
    }

    public function test_expired_restriction_stays_expired_until_new_violation(): void
    {
        $service = app(BookingPolicyService::class);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $profile = CustomerProfile::where('user_id', $customer->id)->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $now = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');

        $this->recordNoShow($service, $branch, $profile, $customer, $owner, $now->subDays(40), 'expired-a');
        $this->recordNoShow($service, $branch, $profile, $customer, $owner, $now->subDays(39), 'expired-b');

        $policy = $service->read($profile->id, $branch->business_id, $now);
        $this->assertSame(4, $policy['score']);
        $this->assertTrue($policy['self_booking_allowed']);
        $this->assertFalse($policy['restriction']['active']);

        $before = CustomerBookingPolicy::query()
            ->where('customer_profile_id', $profile->id)
            ->where('business_id', $branch->business_id)
            ->firstOrFail();
        $beforeEndsAt = $before->getRawOriginal('ends_at');
        $beforeRevision = $before->revision;
        $beforeUpdatedAt = $before->getRawOriginal('updated_at');

        $service->read($profile->id, $branch->business_id, $now);
        $afterRead = $before->fresh();
        $this->assertSame($beforeEndsAt, $afterRead->getRawOriginal('ends_at'));
        $this->assertSame($beforeRevision, $afterRead->revision);
        $this->assertSame($beforeUpdatedAt, $afterRead->getRawOriginal('updated_at'));

        $allowed = DB::transaction(fn () => $service->assertSelfBookingAllowed(
            $profile->id,
            $branch->business_id,
            false,
            $now,
        ));
        $this->assertTrue($allowed['self_booking_allowed']);
        $this->assertFalse($allowed['restriction']['active']);

        $trigger = $this->recordLateCancellation($service, $branch, $profile, $customer, $now, 'expired-reactivate');
        $reactivated = $service->read($profile->id, $branch->business_id, $now->addMinute());
        $this->assertSame(5, $reactivated['score']);
        $this->assertFalse($reactivated['self_booking_allowed']);
        $this->assertTrue($reactivated['restriction']['active']);
        $this->assertSame(
            CarbonImmutable::parse($trigger->getRawOriginal('occurred_at'), 'UTC')->addDays(30)->toIso8601String(),
            $reactivated['restriction']['ends_at'],
        );
    }

    private function recordNoShow(
        BookingPolicyService $service,
        Branch $branch,
        CustomerProfile $profile,
        User $customer,
        User $actor,
        CarbonImmutable $occurredAt,
        string $code,
    ) {
        $booking = $this->policyBooking(
            $branch,
            $profile,
            $customer,
            $occurredAt->subHour(),
            'NO_SHOW',
            $code,
        );

        return DB::transaction(fn () => $service->recordViolation(
            $booking,
            'NO_SHOW',
            $actor->id,
            null,
            $occurredAt,
        ));
    }

    private function recordLateCancellation(
        BookingPolicyService $service,
        Branch $branch,
        CustomerProfile $profile,
        User $customer,
        CarbonImmutable $occurredAt,
        string $code,
    ) {
        $booking = $this->policyBooking(
            $branch,
            $profile,
            $customer,
            $occurredAt->addHour(),
            'CANCELLED',
            $code,
        );
        CarbonImmutable::setTestNow($occurredAt);
        $request = AppointmentChangeRequest::query()->create([
            'booking_id' => $booking->id,
            'requested_by_user_id' => $customer->id,
            'requested_by_type' => 'CUSTOMER',
            'request_type' => 'CANCEL',
            'status' => 'PENDING',
            'expires_at' => $occurredAt->addDay(),
        ]);

        return DB::transaction(fn () => $service->recordViolation(
            $booking,
            'LATE_CANCELLATION',
            $customer->id,
            $request->id,
            $occurredAt,
        ));
    }

    private function policyBooking(
        Branch $branch,
        CustomerProfile $profile,
        User $customer,
        CarbonImmutable $start,
        string $status,
        string $code,
    ): Booking {
        return Booking::query()->create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'customer_profile_id' => $profile->id,
            'created_by_user_id' => $customer->id,
            'booking_code' => 'POLICY-'.strtoupper($code),
            'appointment_start_at' => $start,
            'appointment_end_at' => $start->addMinutes(30),
            'status' => $status,
            'source' => 'ONLINE_WEB',
            'currency' => 'VND',
            'total_amount' => 0,
        ]);
    }
}
