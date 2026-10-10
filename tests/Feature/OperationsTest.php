<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\CustomerProfile;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Operations\LoyaltyService;
use App\Services\Operations\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withSession(['_token' => 'operations-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'operations-csrf']);
    }

    private function fixture(): array
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();

        return [$branch, BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail(), StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail(), User::where('email', 'customer@beautybook.local')->firstOrFail()];
    }

    private function start(): CarbonImmutable
    {
        $start = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTime(10, 0);
        while ($start->dayOfWeek === 0) {
            $start = $start->addDay();
        }

        return $start;
    }

    private function owner(Branch $branch): User
    {
        $owner = User::whereHas('roleAssignments', fn ($q) => $q->where('business_id', $branch->business_id)->where('role_name', 'BUSINESS_OWNER'))->firstOrFail();
        $assignment = $owner->roleAssignments()->where('business_id', $branch->business_id)->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);

        return $owner;
    }

    private function join(): array
    {
        [$branch, $offering, $staff, $customer] = $this->fixture();
        $data = ['branch_service_offering_id' => $offering->id, 'window_start' => $this->start()->subHour()->format('Y-m-d\TH:i'), 'window_end' => $this->start()->addHours(2)->format('Y-m-d\TH:i')];
        $this->actingAs($customer)->postJson(route('waitlist.join', $branch), $data)->assertCreated();

        return [$branch, $offering, $staff, $customer, WaitlistEntry::firstOrFail(), $data];
    }

    public function test_waitlist_join_has_duplicate_guard_and_customer_scope(): void
    {
        [$branch, $offering, $staff, $customer, $entry, $data] = $this->join();
        $this->postJson(route('waitlist.join', $branch), $data)->assertConflict();
        $this->get(route('waitlist.mine'))->assertOk()->assertSee('Danh sách chờ');
        $other = User::factory()->create(['account_kind' => 'CUSTOMER']);
        CustomerProfile::create(['user_id' => $other->id]);
        $this->actingAs($other)->postJson(route('waitlist.cancel', $entry))->assertForbidden();
        $this->actingAs($customer)->postJson(route('waitlist.cancel', $entry))->assertOk();
        $this->postJson(route('waitlist.join', $branch), $data)->assertCreated();
        $this->assertSame(2, WaitlistEntry::count());
    }

    public function test_offer_stores_only_token_hash_and_accept_uses_real_booking_transaction(): void
    {
        [$branch, $offering, $staff, $customer, $entry] = $this->join();
        $this->owner($branch);
        $response = $this->postJson(route('waitlist.offer', $entry), ['start_at' => $this->start()->format('Y-m-d\TH:i'), 'staff_profile_id' => $staff->id, 'ttl_minutes' => 10])->assertOk();
        $token = $response->json('claim_token');
        $this->assertSame(hash('sha256', $token), $entry->refresh()->offer_token_hash);
        $this->assertArrayNotHasKey('offer_token_hash', $response->json('entry'));
        $this->actingAs($customer)->postJson(route('waitlist.accept', $entry), ['token' => str_repeat('0', 64)])->assertConflict();
        $response = $this->postJson(route('waitlist.accept', $entry), ['token' => $token])->assertOk();
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'ACCEPTED', 'booking_id' => $response->json('id'), 'offer_slot_key' => null]);
        $this->postJson(route('waitlist.accept', $entry), ['token' => $token])->assertOk();
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_expired_offer_cannot_be_accepted_and_releases_join_guard(): void
    {
        [$branch, $offering, $staff, $customer, $entry, $data] = $this->join();
        $entry->update(['status' => 'OFFERED', 'staff_profile_id' => $staff->id, 'offered_start_at' => $this->start()->utc(), 'offer_expires_at' => now('UTC')->subMinute(), 'offer_token_hash' => hash('sha256', str_repeat('a', 64)), 'offer_slot_key' => 'expired-test']);
        $this->postJson(route('waitlist.accept', $entry), ['token' => str_repeat('a', 64)])->assertConflict();
        $this->assertSame(1, app(WaitlistService::class)->expireOffers());
        $this->assertNull($entry->refresh()->active_join_key);
        $this->postJson(route('waitlist.join', $branch), $data)->assertCreated();
    }

    public function test_receptionist_cannot_operate_other_branch_waitlist(): void
    {
        [$branch] = $this->fixture();
        $receptionist = User::where('email', 'receptionist@beautybook.local')->firstOrFail();
        $assignment = $receptionist->roleAssignments()->where('role_name', 'RECEPTIONIST')->firstOrFail();
        $other = Branch::where('business_id', $branch->business_id)->whereKeyNot($assignment->branch_id)->firstOrFail();
        $this->actingAs($receptionist)->withSession(['workspace_assignment_id' => $assignment->id])->get(route('operations.waitlist', $other))->assertForbidden();
    }

    public function test_loyalty_rules_are_versioned_scoped_and_balance_is_self_only(): void
    {
        [$branch, $offering, $staff, $customer] = $this->fixture();
        $this->owner($branch);
        $business = Business::findOrFail($branch->business_id);
        $data = ['earn_points_per_amount' => 1, 'earn_amount_unit' => '10000.00', 'redemption_value_per_point' => '1000.00'];
        $this->postJson(route('owner.loyalty.configure', $business), $data)->assertCreated()->assertJsonPath('version', 1);
        $this->postJson(route('owner.loyalty.configure', $business), $data)->assertCreated()->assertJsonPath('version', 2);
        $this->assertSame(1, DB::table('loyalty_rules')->where('active', true)->count());
        $other = Branch::where('business_id', '!=', $business->id)->firstOrFail();
        $this->postJson(route('owner.loyalty.configure', $business), $data + ['branch_id' => $other->id])->assertUnprocessable();
        $account = LoyaltyAccount::create(['business_id' => $business->id, 'customer_profile_id' => $customer->customerProfile->id, 'balance' => 50]);
        $this->actingAs($customer)->getJson(route('loyalty.mine'))->assertOk()->assertJsonPath('0.balance', 50);
        $this->get(route('loyalty.mine'))->assertOk()->assertSee('Điểm thưởng');
    }

    public function test_loyalty_redemption_in_booking_is_atomic_idempotent_and_reversible(): void
    {
        [$branch,$offering,$staff,$customer] = $this->fixture();
        $owner = $this->owner($branch);
        app(LoyaltyService::class)->configure(Business::findOrFail($branch->business_id), $owner->id, ['earn_points_per_amount' => 1, 'earn_amount_unit' => '10000.00', 'redemption_value_per_point' => '1000.00']);
        $account = LoyaltyAccount::create(['business_id' => $branch->business_id, 'customer_profile_id' => $customer->customerProfile->id, 'balance' => 50]);
        $this->actingAs($customer)->withHeaders(['Idempotency-Key' => 'loyalty-booking']);
        $data = ['branch_service_offering_id' => $offering->id, 'staff_profile_id' => $staff->id, 'start_at' => $this->start()->format('Y-m-d\TH:i'), 'loyalty_points' => 10];
        $this->postJson(route('booking.store', $branch), $data)->assertCreated();
        $this->postJson(route('booking.store', $branch), $data)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertSame(40, $account->refresh()->balance);
        $booking = Booking::with('pricingSnapshot')->firstOrFail();
        $this->assertSame('10000.00', $booking->pricingSnapshot->discount_amount);
        $this->assertSame(10, $booking->pricingSnapshot->source_snapshot['loyalty']['points']);
        app(LoyaltyService::class)->reverseRedemption($booking);
        app(LoyaltyService::class)->reverseRedemption($booking);
        $this->assertSame(50, $account->refresh()->balance);
        $this->assertSame(2, LoyaltyTransaction::count());
    }

    public function test_earn_does_not_award_unpaid_completed_booking(): void
    {
        [$branch,$offering,$staff,$customer] = $this->fixture();
        $owner = $this->owner($branch);
        app(LoyaltyService::class)->configure(Business::findOrFail($branch->business_id), $owner->id, ['earn_points_per_amount' => 1, 'earn_amount_unit' => '10000.00', 'redemption_value_per_point' => '1000.00']);
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id, 'booking_code' => 'LOYALTY-UNPAID', 'appointment_start_at' => now()->subDay(), 'appointment_end_at' => now()->subDay()->addHour(), 'status' => 'COMPLETED', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => '100000.00']);
        $this->assertNull(app(LoyaltyService::class)->earnForBooking($booking->id));
        $this->assertDatabaseCount('loyalty_transactions', 0);
    }

    public function test_verified_payment_awards_once_and_refund_reconciles_points(): void
    {
        [$branch,$offering,$staff,$customer] = $this->fixture();
        $owner = $this->owner($branch);
        $service = app(LoyaltyService::class);
        $service->configure(Business::findOrFail($branch->business_id), $owner->id, ['earn_points_per_amount' => 1, 'earn_amount_unit' => '10000.00', 'redemption_value_per_point' => '1000.00']);
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id, 'booking_code' => 'LOYALTY-PAID', 'appointment_start_at' => now()->subDay(), 'appointment_end_at' => now()->subDay()->addHour(), 'status' => 'COMPLETED', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => '100000.00']);
        $paymentId = DB::table('payment_transactions')->insertGetId(['booking_id' => $booking->id, 'amount_cents' => 10000000, 'method' => 'CASH', 'status' => 'VERIFIED', 'idempotency_key' => 'LOYALTY-PAID', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $earn = $service->earnForBooking($booking->id, $owner->id);
        $this->assertSame(10, $earn->points);
        $this->assertSame($earn->id, $service->earnForBooking($booking->id, $owner->id)->id);
        $refundId = DB::table('refund_requests')->insertGetId(['payment_transaction_id' => $paymentId, 'amount_cents' => 3000000, 'reason' => 'Hoàn một phần', 'status' => 'REFUNDED', 'requested_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $adjust = $service->reconcileEarn($booking->id, 'REFUND:'.$refundId, $owner->id);
        $this->assertSame(-3, $adjust->points);
        $this->assertSame($adjust->id, $service->reconcileEarn($booking->id, 'REFUND:'.$refundId, $owner->id)->id);
        $this->assertSame(7, LoyaltyAccount::firstOrFail()->balance);
    }
}
