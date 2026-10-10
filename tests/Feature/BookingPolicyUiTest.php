<?php

namespace Tests\Feature;

use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\CustomerProfile;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Booking\BookingPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BookingPolicyUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_customer_sees_warning_at_two_points_without_acknowledgment_checkbox(): void
    {
        [$customer, $profile, $branch, $owner] = $this->policyFixture();
        $occurredAt = CarbonImmutable::now('UTC')->subDay();

        $this->recordNoShow($profile, $branch, $customer, $owner, $occurredAt, 'warning-two');

        $this->actingAs($customer)
            ->get(route('booking.create', $branch))
            ->assertOk()
            ->assertSee('Bạn đang có 2 điểm vi phạm')
            ->assertSee('data-booking-policy-warning', false)
            ->assertDontSee('name="violation_acknowledged"', false);
    }

    public function test_customer_at_three_points_must_acknowledge_before_self_booking(): void
    {
        [$customer, $profile, $branch, $owner] = $this->policyFixture();
        $base = CarbonImmutable::now('UTC')->subDays(2);

        $this->recordNoShow($profile, $branch, $customer, $owner, $base, 'ack-no-show');
        $this->recordLateCancellation($profile, $branch, $customer, $base->addDay(), 'ack-late');

        $this->actingAs($customer)
            ->get(route('booking.create', $branch))
            ->assertOk()
            ->assertSee('Cần xác nhận trước khi đặt lịch')
            ->assertSee('name="violation_acknowledged"', false)
            ->assertSee('Tôi đã hiểu cảnh báo 3 điểm');

        [$offering, $staff] = $this->bookingSelection($branch);
        $payload = $this->bookingPayload($offering, $staff);

        $this->postBooking($branch, $payload, 'policy-ui-no-ack')
            ->assertConflict()
            ->assertJsonPath('message', 'Vui lòng xác nhận đã hiểu cảnh báo 3 điểm trước khi tiếp tục đặt lịch.');

        $payload['violation_acknowledged'] = true;

        $this->postBooking($branch, $payload, 'policy-ui-with-ack')
            ->assertCreated();
    }

    public function test_active_restriction_disables_self_booking_only_for_the_affected_business(): void
    {
        [$customer, $profile, $branch, $owner] = $this->policyFixture();
        $base = CarbonImmutable::now('UTC')->subDays(2);

        $this->recordNoShow($profile, $branch, $customer, $owner, $base, 'restriction-one');
        $this->recordNoShow($profile, $branch, $customer, $owner, $base->addDay(), 'restriction-two');

        $this->actingAs($customer)
            ->get(route('booking.create', $branch))
            ->assertOk()
            ->assertSee('Tự đặt lịch đang bị giới hạn tại doanh nghiệp này')
            ->assertSee('data-booking-policy-restriction', false)
            ->assertSee('data-self-booking-allowed="false"', false)
            ->assertSee('data-check-availability disabled', false);

        $otherBusinessBranch = Branch::query()
            ->where('business_id', '!=', $branch->business_id)
            ->whereNotNull('published_at')
            ->firstOrFail();

        $this->actingAs($customer)
            ->get(route('booking.create', $otherBusinessBranch))
            ->assertOk()
            ->assertDontSee('Tự đặt lịch đang bị giới hạn tại doanh nghiệp này')
            ->assertSee('data-self-booking-allowed="true"', false);
    }

    private function policyFixture(): array
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $profile = CustomerProfile::where('user_id', $customer->id)->firstOrFail();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();

        return [$customer, $profile, $branch, $owner];
    }

    private function recordNoShow(
        CustomerProfile $profile,
        Branch $branch,
        User $customer,
        User $actor,
        CarbonImmutable $occurredAt,
        string $code,
    ): void {
        $booking = $this->policyBooking(
            $profile,
            $branch,
            $customer,
            $occurredAt->subHour(),
            'NO_SHOW',
            $code,
        );

        DB::transaction(fn () => app(BookingPolicyService::class)->recordViolation(
            $booking,
            'NO_SHOW',
            $actor->id,
            null,
            $occurredAt,
        ));
    }

    private function recordLateCancellation(
        CustomerProfile $profile,
        Branch $branch,
        User $customer,
        CarbonImmutable $occurredAt,
        string $code,
    ): void {
        $booking = $this->policyBooking(
            $profile,
            $branch,
            $customer,
            $occurredAt->addHour(),
            'CANCELLED',
            $code,
        );

        CarbonImmutable::setTestNow($occurredAt);

        try {
            $request = AppointmentChangeRequest::query()->create([
                'booking_id' => $booking->id,
                'requested_by_user_id' => $customer->id,
                'requested_by_type' => 'CUSTOMER',
                'request_type' => 'CANCEL',
                'status' => 'PENDING',
                'expires_at' => $occurredAt->addDay(),
            ]);

            DB::transaction(fn () => app(BookingPolicyService::class)->recordViolation(
                $booking,
                'LATE_CANCELLATION',
                $customer->id,
                $request->id,
                $occurredAt,
            ));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function policyBooking(
        CustomerProfile $profile,
        Branch $branch,
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
            'booking_code' => 'POLICY-UI-'.strtoupper($code),
            'appointment_start_at' => $start,
            'appointment_end_at' => $start->addMinutes(30),
            'status' => $status,
            'source' => 'ONLINE_WEB',
            'currency' => 'VND',
            'total_amount' => 0,
        ]);
    }

    private function bookingSelection(Branch $branch): array
    {
        $offering = BranchServiceOffering::query()
            ->where('branch_id', $branch->id)
            ->orderBy('id')
            ->firstOrFail();
        $staff = StaffProfile::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'ACTIVE')
            ->firstOrFail();

        return [$offering, $staff];
    }

    private function bookingPayload(BranchServiceOffering $offering, StaffProfile $staff): array
    {
        $start = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTime(10, 0);

        while ($start->dayOfWeek === 0) {
            $start = $start->addDay();
        }

        return [
            'branch_service_offering_id' => $offering->id,
            'service_variant_id' => null,
            'staff_profile_id' => $staff->id,
            'start_at' => $start->format('Y-m-d\TH:i'),
        ];
    }

    private function postBooking(Branch $branch, array $payload, string $idempotencyKey): TestResponse
    {
        $token = 'beautybook-policy-ui-csrf-token';

        return $this->withSession(['_token' => $token])
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->postJson(route('booking.store', $branch), $payload);
    }
}

