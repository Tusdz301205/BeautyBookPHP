<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Scheduling\ComboService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BeautybookBookingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function actor(string $email): User
    {
        $u = User::where('email', $email)->firstOrFail();
        $a = $u->roleAssignments()->firstOrFail();
        $token = 'booking-api-test-token';
        $this->actingAs($u)->withHeader('Authorization', 'Bearer '.$token)->withSession(['workspace_assignment_id' => $a->id, 'beautybook_access_hash' => hash('sha256', $token), 'beautybook_access_expires' => time() + 3600]);

        return $u;
    }

    private function data(): array
    {
        $b = Branch::where('slug', 'tan-tao')->firstOrFail();
        $services = BranchServiceOffering::where('branch_id', $b->id)->orderBy('id')->take(2)->get();
        $staff = StaffProfile::where('branch_id', $b->id)->where('status', 'ACTIVE')->firstOrFail();
        foreach ($services as $s) {
            $staff->offerings()->syncWithoutDetaching([$s->id => ['business_id' => $b->business_id, 'branch_id' => $b->id]]);
        }
        $at = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->setTime(10, 0);
        while ($at->dayOfWeek === 0) {
            $at = $at->addDay();
        }

        return ['branchId' => (string) $b->id, 'serviceIds' => $services->pluck('id')->map(fn ($id) => (string) $id)->all(), 'staffId' => (string) $staff->id,
            'appointmentDate' => $at->toIso8601String(), 'source' => 'ONLINE_WEB', 'customerName' => 'Tên liên hệ riêng', 'customerPhone' => '0901234567', 'contactConsent' => true];
    }

    public function test_multiservice_contract_real_prices_slots_create_replay_and_customer_scope(): void
    {
        $this->actor('customer@beautybook.local');
        $d = $this->data();
        $preview = $this->postJson('/api/v1/bookings/preview-price', $d)->assertOk()->assertJsonCount(2, 'items')->json();
        $this->assertGreaterThan(0, (float) $preview['finalAmount']);
        $slot = $this->getJson('/api/v1/bookings/available-slots?'.http_build_query($d + ['date' => substr($d['appointmentDate'], 0, 10)]))->assertOk()->json('slots.0');
        $this->assertNotEmpty($slot['start']);
        $this->assertNotEmpty($slot['end']);
        $d['appointmentDate'] = $slot['start'];
        $response = $this->withHeader('Idempotency-Key', 'api-one-cart')->postJson('/api/v1/bookings', $d)->assertCreated()->assertJsonCount(2, 'bookingServices')->assertJsonPath('replayed', false);
        $id = $response->json('id');
        $this->assertIsString($id);
        $this->postJson('/api/v1/bookings', $d)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('id', $id);
        $this->getJson('/api/v1/bookings/'.$id)->assertOk()->assertJsonPath('branchId', $d['branchId']);
        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_service_price_rules_use_local_date_and_quote_snapshot(): void
    {
        $this->actor('customer@beautybook.local');
        $d = $this->data();
        DB::table('service_price_rules')->insert(['branch_service_offering_id' => $d['serviceIds'][0], 'name' => 'Morning surcharge', 'priority' => 1, 'adjustment_type' => 'FIXED_AMOUNT', 'adjustment_value' => '12345.67', 'conditions' => json_encode(['startTime' => '09:00', 'endTime' => '11:00', 'staffId' => $d['staffId']]), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $base = BranchServiceOffering::whereIn('id', $d['serviceIds'])->get()->sum(fn ($s) => Money::cents($s->price));
        $total = Money::decimal($base + 1234567);
        $this->postJson('/api/v1/bookings/preview-price', $d)->assertOk()->assertJsonPath('finalAmount', $total);
        $this->withHeader('Idempotency-Key', 'api-rule-price')->postJson('/api/v1/bookings', $d)->assertCreated()->assertJsonPath('totalAmount', $total);
        $this->assertNotEmpty(Booking::firstOrFail()->pricingSnapshot->source_snapshot['service_price_rules'][0]);
    }

    public function test_authoritative_holiday_blocks_advertising_and_booking(): void
    {
        $this->actor('customer@beautybook.local');
        $d = $this->data();
        DB::table('beautybook_branch_calendar')->insert(['branch_id' => $d['branchId'], 'date' => substr($d['appointmentDate'], 0, 10), 'name' => 'Holiday', 'is_closed' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/v1/bookings/available-slots?'.http_build_query($d + ['date' => substr($d['appointmentDate'], 0, 10)]))->assertOk()->assertJsonCount(0, 'slots');
        $this->withHeader('Idempotency-Key', 'api-holiday')->postJson('/api/v1/bookings', $d)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_owner_scheduler_resize_reprice_and_payment_persist_exact_amounts(): void
    {
        $this->actor('customer@beautybook.local');
        $d = $this->data();
        $b = $this->withHeader('Idempotency-Key', 'api-owner-book')->postJson('/api/v1/bookings', $d)->assertCreated()->json();
        $this->assertStringContainsString('T10:00:00', $b['appointmentStartTime']);
        $this->actor('owner@beautybook.local');
        $end = CarbonImmutable::parse($b['appointmentEndAt'])->addMinutes(15)->toIso8601String();
        $this->patchJson('/api/v1/bookings/'.$b['id'].'/resize', ['newEndTime' => $end])->assertOk()->assertJsonPath('appointmentEndAt', $end);
        $item = $this->getJson('/api/v1/bookings/'.$b['id'])->json('bookingServices.0');
        $this->patchJson('/api/v1/bookings/'.$b['id'].'/items/'.$item['id'], ['action' => 'REPRICE', 'expectedRevision' => $item['revision'], 'price' => '100.01', 'reason' => 'Correct sale price'])->assertOk()->assertJsonPath('bookingServices.0.priceAtBooking', '100.01');
        $current = Booking::findOrFail($b['id']);
        $this->postJson('/api/v1/payments/collect', ['bookingId' => $current->booking_code, 'method' => 'CASH', 'idempotencyKey' => 'api-payment-one'])->assertOk()->assertJsonPath('status', 'VERIFIED')->assertJsonPath('amount', $current->total_amount);
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonPath('0.status', 'PAID');
        $this->getJson('/api/v1/payments/checkout/'.$b['id'])->assertOk()->assertJsonPath('remainingAmount', '0.00');
        $this->assertDatabaseCount('beautybook_booking_edits', 2);
    }

    public function test_recurring_multiservice_creates_real_atomic_occurrences(): void
    {
        $this->actor('customer@beautybook.local');
        $d = $this->data();
        $payload = ['branchId' => $d['branchId'], 'serviceIds' => $d['serviceIds'], 'staffId' => $d['staffId'], 'staffMode' => 'SAME_STAFF', 'frequency' => 'WEEKLY', 'startDate' => substr($d['appointmentDate'], 0, 10), 'preferredTime' => '10:00', 'occurrenceCount' => 2, 'skipConflicts' => false, 'customerName' => $d['customerName'], 'customerPhone' => $d['customerPhone'], 'contactConsent' => true];
        $this->postJson('/api/v1/recurring/preview', $payload)->assertOk()->assertJsonPath('availableCount', 2);
        $this->withHeader('Idempotency-Key', 'api-recurring-one')->postJson('/api/v1/recurring', $payload)->assertOk()->assertJsonCount(2, 'occurrences')->assertJsonCount(2, 'bookings');
        $this->assertDatabaseCount('bookings', 2);
        $this->assertDatabaseCount('booking_services', 4);
        $this->assertDatabaseCount('booking_contacts', 2);
        $this->postJson('/api/v1/recurring', $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_contact_consent_validation_and_booking_scope_are_authoritative(): void
    {
        $this->actor('customer@beautybook.local');
        $d = $this->data();
        $bad = $d;
        $bad['contactConsent'] = false;
        $this->withHeader('Idempotency-Key', 'api-bad-consent')->postJson('/api/v1/bookings', $bad)->assertUnprocessable();
        $bad = $d;
        $bad['customerPhone'] = 'fake';
        $this->postJson('/api/v1/bookings', $bad)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'api-good-contact')->postJson('/api/v1/bookings', $d)->assertCreated()->assertJsonPath('customer.user.fullName', $d['customerName'])->assertJsonPath('customer.user.phone', $d['customerPhone']);
        $b = Booking::firstOrFail();
        $this->assertTrue($b->pricingSnapshot->source_snapshot['contact_consent']);
        $this->actor('owner2@beautybook.local');
        $this->getJson('/api/v1/bookings/'.$b->id)->assertNotFound();
        $this->getJson('/api/v1/bookings/scheduler?'.http_build_query(['branchId' => $b->branch_id, 'startDate' => substr($d['appointmentDate'], 0, 10), 'endDate' => substr($d['appointmentDate'], 0, 10)]))->assertForbidden();
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_controlled_overbooking_stays_unassigned_and_never_bypasses_named_staff_conflict(): void
    {
        $this->actor('owner@beautybook.local');
        $d = $this->data();
        unset($d['customerName'],$d['customerPhone'],$d['contactConsent'],$d['staffId']);
        $d += ['guestName' => 'Khách tại quầy', 'guestPhone' => '0901234567', 'controlledOverbooking' => true, 'overbookingReason' => 'Đã thống nhất kế hoạch phân công'];
        $d['source'] = 'WALK_IN';
        DB::table('beautybook_branch_settings')->insert(['branch_id' => $d['branchId'], 'booking_policy' => json_encode(['overbookingEnabled' => true, 'maxOverbookedSlots' => 1]), 'onboarding' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/bookings', $d)->assertCreated()->assertJsonPath('bookingServices.0.staffId', null)->assertJsonPath('bookingServices.1.staffId', null);
        $this->postJson('/api/v1/bookings', $d)->assertConflict();
        $d['staffId'] = (string) StaffProfile::where('branch_id', $d['branchId'])->firstOrFail()->id;
        $this->postJson('/api/v1/bookings', $d)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_source_item_completion_records_actual_time_without_completing_appointment(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07T03:15:00Z'));
        $this->actor('customer@beautybook.local');
        $book = $this->withHeader('Idempotency-Key', 'api-execution')->postJson('/api/v1/bookings', $this->data())->assertCreated()->json();
        $otherData = $this->data();
        $otherData['appointmentDate'] = CarbonImmutable::parse($otherData['appointmentDate'])->addWeek()->toIso8601String();
        $otherBook = $this->withHeader('Idempotency-Key', 'api-running-overrun')->postJson('/api/v1/bookings', $otherData)->assertCreated()->json();
        $running = BookingService::findOrFail($otherBook['bookingServices'][0]['id']);
        $running->update(['status' => 'IN_PROGRESS', 'item_start_at' => now()->subMinutes(120), 'item_end_at' => now()->subMinutes(60), 'occupied_start_at' => now()->subMinutes(120), 'occupied_end_at' => now()->subMinutes(60)]);
        DB::table('booking_services')->where('id', $running->id)->update(['actual_started_at' => CarbonImmutable::now('UTC')->subMinutes(120)]);
        $booking = Booking::findOrFail($book['id']);
        $booking->update(['status' => 'CHECKED_IN', 'appointment_start_at' => now()->subMinutes(15), 'appointment_end_at' => now()->addMinutes(45)]);
        $items = $booking->services()->orderBy('id')->get();
        $items[0]->update(['item_start_at' => now()->subMinutes(15), 'item_end_at' => now()->addMinutes(15), 'occupied_start_at' => now()->subMinutes(15), 'occupied_end_at' => now()->addMinutes(15), 'duration_minutes' => 30]);
        $items[1]->update(['item_start_at' => now()->addMinutes(15), 'item_end_at' => now()->addMinutes(45), 'occupied_start_at' => now()->addMinutes(15), 'occupied_end_at' => now()->addMinutes(45), 'duration_minutes' => 30]);
        $url = '/api/v1/bookings/'.$booking->id.'/items/'.$items[0]->id;
        $this->patchJson($url, ['action' => 'START', 'expectedRevision' => 1, 'reason' => 'Bắt đầu thực hiện'])->assertForbidden();
        $this->actor('owner@beautybook.local');
        $this->patchJson($url, ['action' => 'START', 'expectedRevision' => 1, 'reason' => 'Bắt đầu khi lịch khác quá giờ'])->assertConflict();
        $running->update(['status' => 'COMPLETED']);
        DB::table('booking_services')->where('id', $running->id)->update(['actual_completed_at' => CarbonImmutable::now('UTC')->subMinute()]);
        $this->patchJson($url, ['action' => 'START', 'expectedRevision' => 1, 'reason' => 'Bắt đầu thực hiện'])->assertOk()->assertJsonPath('status', 'IN_PROGRESS')->assertJsonPath('bookingServices.0.status', 'IN_PROGRESS');
        $this->patchJson($url, ['action' => 'COMPLETE', 'expectedRevision' => 1, 'reason' => 'Hoàn tất'])->assertConflict();
        $this->patchJson($url, ['action' => 'COMPLETE', 'expectedRevision' => 2, 'reason' => 'Hoàn tất trước dự kiến'])->assertOk()->assertJsonPath('status', 'IN_PROGRESS')->assertJsonPath('bookingServices.0.status', 'COMPLETED')->assertJsonPath('readyToComplete', false);
        $item = $items[0]->refresh();
        $this->assertNotNull($item->actual_started_at);
        $this->assertNotNull($item->actual_completed_at);
        $this->assertTrue(CarbonImmutable::parse($item->actual_completed_at)->lessThan($item->item_end_at));
        $this->assertDatabaseCount('beautybook_booking_edits', 2);
        $this->patchJson($url, ['action' => 'REASSIGN', 'expectedRevision' => 3, 'staffId' => (string) $item->staff_profile_id, 'reason' => 'Phân công lại'])->assertConflict();
    }

    public function test_grouped_payment_refund_reserves_across_verified_transactions_atomically(): void
    {
        $this->actor('customer@beautybook.local');
        $book = $this->withHeader('Idempotency-Key', 'api-split-refund')->postJson('/api/v1/bookings', $this->data())->assertCreated()->json();
        $this->actor('owner@beautybook.local');
        $total = Money::cents($book['totalAmount']);
        $first = $this->postJson('/api/v1/payments/collect', ['bookingId' => $book['id'], 'method' => 'CASH', 'amount' => Money::decimal(10000), 'idempotencyKey' => 'api-split-payment-1'])->assertOk()->json();
        $this->postJson('/api/v1/payments/'.$first['id'].'/refund-requests', ['amount' => '50.00', 'reason' => 'Hoàn tiền theo yêu cầu'])->assertConflict();
        $this->postJson('/api/v1/payments/collect', ['bookingId' => $book['id'], 'method' => 'CASH', 'amount' => Money::decimal($total - 10000), 'idempotencyKey' => 'api-split-payment-2'])->assertOk();
        $this->postJson('/api/v1/payments/'.$first['id'].'/refund-requests', ['amount' => $book['totalAmount'], 'reason' => 'Hoàn tiền theo yêu cầu'])->assertOk()->assertJsonCount(2, 'refundRequests');
        $this->assertSame($total, (int) DB::table('refund_requests')->sum('amount_cents'));
        DB::table('refund_requests')->where('payment_transaction_id', $first['id'])->update(['status' => 'REFUNDED']);
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonPath('0.status', 'PARTIALLY_REFUNDED')->assertJsonPath('0.amount', $book['totalAmount'])->assertJsonPath('0.netAmount', Money::decimal($total - 10000));
        $this->postJson('/api/v1/payments/'.$first['id'].'/refund-requests', ['amount' => '0.01', 'reason' => 'Yêu cầu vượt số dư'])->assertUnprocessable();
        $this->assertDatabaseCount('refund_requests', 2);
    }

    public function test_scheduler_cannot_resize_an_internal_item_or_remove_the_last_active_item(): void
    {
        $this->actor('customer@beautybook.local');
        $book = $this->withHeader('Idempotency-Key', 'api-command-guards')->postJson('/api/v1/bookings', $this->data())->assertCreated()->json();
        $this->actor('owner@beautybook.local');
        $url = '/api/v1/bookings/'.$book['id'].'/items/';
        $first = $book['bookingServices'][0];
        $second = $book['bookingServices'][1];
        $this->patchJson($url.$first['id'], ['action' => 'RESIZE', 'expectedRevision' => 1, 'durationMinutes' => 60, 'reason' => 'Kéo dài dịch vụ'])->assertConflict();
        $this->patchJson($url.$second['id'], ['action' => 'REMOVE', 'expectedRevision' => 1, 'reason' => 'Khách đổi dịch vụ'])->assertOk();
        $this->patchJson($url.$first['id'], ['action' => 'REMOVE', 'expectedRevision' => 1, 'reason' => 'Khách đổi dịch vụ'])->assertConflict();
        $this->assertDatabaseCount('beautybook_booking_edits', 1);
    }

    public function test_actual_correction_requires_strict_observed_intervals_and_can_reconcile_scheduled_history(): void
    {
        $this->actor('customer@beautybook.local');
        $book = $this->withHeader('Idempotency-Key', 'api-actual-history')->postJson('/api/v1/bookings', $this->data())->assertCreated()->json();
        $this->actor('owner@beautybook.local');
        $first = $book['bookingServices'][0];
        $second = $book['bookingServices'][1];
        $url = '/api/v1/bookings/'.$book['id'].'/items/';
        $start = CarbonImmutable::now('UTC')->subMinutes(60)->toIso8601String();
        $end = CarbonImmutable::now('UTC')->subMinutes(30)->toIso8601String();
        $base = ['actualStartedAt' => $start, 'actualCompletedAt' => $end, 'reason' => 'Đối soát lịch sử thực hiện', 'expectedRevision' => 1];
        $this->getJson('/api/v1/bookings/'.$book['id'])->assertOk()->assertJsonPath('bookingServices.0.canCorrectActualTime', true);
        $this->patchJson($url.$first['id'].'/actual-time', array_replace($base, ['actualCompletedAt' => null]))->assertUnprocessable();
        $this->patchJson($url.$first['id'].'/actual-time', array_replace($base, ['actualCompletedAt' => $start]))->assertUnprocessable();
        $this->patchJson($url.$first['id'].'/actual-time', array_replace($base, ['actualStartedAt' => substr($start, 0, 19)]))->assertUnprocessable();
        $this->patchJson($url.$first['id'].'/actual-time', array_replace($base, ['actualTimingStatus' => 'UNKNOWN']))->assertUnprocessable();
        $this->patchJson($url.$first['id'].'/actual-time', $base)->assertOk()->assertJsonPath('status', 'COMPLETED')->assertJsonPath('revision', 2);
        $this->getJson($url.$first['id'].'/actual-time')->assertOk()->assertJsonPath('data.0.version', 1)->assertJsonPath('data.0.oldItemStatus', 'SCHEDULED')->assertJsonPath('data.0.newItemStatus', 'COMPLETED');
        $this->patchJson($url.$second['id'].'/actual-time', $base)->assertConflict();
        $this->patchJson($url.$second['id'].'/actual-time', array_replace($base, ['actualStartedAt' => null, 'actualCompletedAt' => null, 'actualTimingStatus' => 'UNKNOWN']))->assertOk()->assertJsonPath('status', 'COMPLETED')->assertJsonPath('actualTimingStatus', 'UNKNOWN');
        $this->assertDatabaseCount('beautybook_booking_edits', 2);
        $this->assertSame('PENDING', Booking::findOrFail($book['id'])->status);
    }

    public function test_combo_auto_preview_create_and_actual_time_history_permissions(): void
    {
        $this->actor('customer@beautybook.local');
        $d = $this->data();
        $branch = Branch::findOrFail($d['branchId']);
        $combo = app(ComboService::class)->save($branch, ['name' => 'Gói thử tương thích', 'combo_price' => '200001.23', 'status' => 'ACTIVE', 'pricing_mode' => 'FIXED_PRICE', 'staff_assignment_mode' => 'SINGLE_PROVIDER', 'items' => array_map(fn ($id) => ['branch_service_offering_id' => (int) $id, 'quantity' => 1, 'transition_minutes' => 0], $d['serviceIds'])]);
        unset($d['staffId']);
        $d['comboId'] = (string) $combo->id;
        $this->postJson('/api/v1/bookings/preview-price', $d)->assertOk()->assertJsonPath('finalAmount', '200001.23');
        $book = $this->withHeader('Idempotency-Key', 'api-combo-auto')->postJson('/api/v1/bookings', $d)->assertCreated()->assertJsonCount(2, 'bookingServices')->assertJsonPath('totalAmount', '200001.23')->assertJsonPath('customer.user.fullName', $d['customerName'])->json();
        $this->assertDatabaseCount('combo_booking_usages', 1);
        $item = BookingService::findOrFail($book['bookingServices'][0]['id']);
        $item->update(['status' => 'COMPLETED']);
        $booking = Booking::findOrFail($book['id']);
        $booking->update(['status' => 'COMPLETED']);
        $this->getJson('/api/v1/bookings/'.$booking->id.'/items/'.$item->id.'/actual-time')->assertForbidden();
        $this->actor('owner@beautybook.local');
        $start = now()->subMinutes(60)->toIso8601String();
        $end = now()->subMinutes(30)->toIso8601String();
        $this->patchJson('/api/v1/bookings/'.$booking->id.'/items/'.$item->id.'/actual-time', ['actualStartedAt' => $start, 'actualCompletedAt' => $end, 'reason' => 'Đối chiếu sổ vận hành', 'expectedRevision' => 1])->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('actualTimingStatus', 'KNOWN');
        $this->getJson('/api/v1/bookings/'.$booking->id.'/items/'.$item->id.'/actual-time')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Đối chiếu sổ vận hành');
        $this->patchJson('/api/v1/bookings/'.$booking->id.'/items/'.$item->id.'/actual-time', ['actualStartedAt' => $start, 'actualCompletedAt' => $end, 'reason' => 'Sửa lặp lại', 'expectedRevision' => 1])->assertConflict();
        $this->assertDatabaseCount('beautybook_booking_edits', 1);
    }
}
