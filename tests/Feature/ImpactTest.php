<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\OperationalImpactCase;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Operations\ImpactService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImpactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_branch_case_snapshots_scope_and_blade_render(): void
    {
        $booking = $this->booking();
        $case = $this->impact($booking);
        $this->assertCount(1, $case->items);
        $this->assertSame($booking->id, $case->snapshot[0]['booking_id']);
        $this->actor('owner');
        $this->getJson(route('impacts.show', $case))->assertOk()->assertJsonCount(1, 'items');
        $this->get(route('impacts.show', $case))->assertOk()->assertSee('Hồ sơ ảnh hưởng');
        $this->get(route('impacts.index'))->assertOk()->assertSee('Ảnh hưởng vận hành');
        $this->actor('owner2');
        $this->getJson(route('impacts.show', $case))->assertForbidden();
        $this->getJson(route('impacts.index'))->assertJsonCount(0, 'data');
        $this->actor('customer');
        $this->getJson(route('impacts.index'))->assertForbidden();
    }

    public function test_stale_resolved_booking_reopens_before_completion_and_closure(): void
    {
        $booking = $this->booking(); $case = $this->impact($booking); $this->actor('owner');
        $this->resolve($case, ['resolution' => 'APPROVED_EXCEPTION', 'reason' => 'Đã liên hệ và xác nhận ngoại lệ'])->assertOk()->assertJsonPath('status', 'READY_TO_COMPLETE');
        $this->postJson(route('impacts.complete', $case))->assertOk()->assertJsonPath('status', 'COMPLETED');
        $sameCase = app(ImpactService::class)->openForBranch($booking->branch, User::where('email', 'owner@beautybook.local')->firstOrFail()->id, 'SUSPEND', 'Kiểm tra trước khi thực hiện');
        $this->assertSame($case->id, $sameCase->id);
        $this->assertSame('COMPLETED', $sameCase->status);
        $item = $booking->services()->firstOrFail(); $item->increment('revision');
        $this->postJson(route('impacts.complete', $case))->assertConflict();
        $this->assertSame('IN_PROGRESS', $case->fresh()->status);
        $this->assertSame('PENDING', $case->items()->first()->status);
        $this->assertFalse(app(ImpactService::class)->refreshForClosure($booking->branch));
    }

    public function test_new_booking_is_detected_after_previous_case_completion(): void
    {
        $booking = $this->booking(); $case = $this->impact($booking); $this->actor('owner');
        $this->resolve($case, ['resolution' => 'APPROVED_EXCEPTION', 'reason' => 'Ngoại lệ đã xác nhận'])->assertOk();
        $this->postJson(route('impacts.complete', $case))->assertOk();
        $this->booking(1);
        $this->assertFalse(app(ImpactService::class)->refreshForClosure($booking->branch));
        $this->assertSame('IN_PROGRESS', $case->fresh()->status);
        $this->assertSame(2, $case->items()->count());
    }

    public function test_cancel_refund_reserves_verified_amount_and_never_marks_money_refunded(): void
    {
        $booking = $this->booking(); $case = $this->impact($booking); $this->actor('owner');
        $this->postJson(route('finance.collect', $booking), ['method' => 'CASH', 'amount' => '1000.00', 'idempotency_key' => 'impact-cash-one'])->assertCreated();
        $this->resolve($case, ['resolution' => 'CANCEL_REFUND', 'reason' => 'Chi nhánh tạm dừng'])->assertOk()->assertJsonPath('status', 'READY_TO_COMPLETE');
        $this->assertSame('CANCELLED', $booking->fresh()->status);
        $this->assertDatabaseHas('refund_requests', ['amount_cents' => 100000, 'status' => 'PENDING']);
        $this->assertDatabaseMissing('refund_requests', ['status' => 'REFUNDED']);
        $this->assertSame(0, DB::table('payment_ledger_entries')->where('type', 'REFUND')->count());
        $this->resolve($case, ['resolution' => 'CANCEL_REFUND', 'reason' => 'Retry'])->assertConflict();
        $this->assertDatabaseCount('refund_requests', 1);
    }

    public function test_reschedule_rechecks_open_hours_conflicts_and_preserves_price_snapshot(): void
    {
        $booking = $this->booking(); $case = $this->impact($booking); $this->actor('owner');
        $snapshot = $booking->pricingSnapshot->source_snapshot;
        $date = $booking->appointment_start_at->copy()->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d');
        $this->resolve($case, ['resolution' => 'RESCHEDULE', 'proposed_start_at' => $date.'T23:00', 'reason' => 'Giờ ngoài phục vụ'])->assertUnprocessable();
        $this->assertSame('PENDING', $case->items()->first()->status);
        $this->resolve($case, ['resolution' => 'RESCHEDULE', 'proposed_start_at' => $date.'T14:00', 'reason' => 'Khách đã đồng ý giờ mới'])->assertOk();
        $this->assertSame('14:00', $booking->fresh()->appointment_start_at->setTimezone('Asia/Ho_Chi_Minh')->format('H:i'));
        $this->assertSame($snapshot, $booking->pricingSnapshot()->first()->source_snapshot);
        $this->assertSame(2, $booking->services()->first()->revision);
    }

    public function test_reassign_requires_authoritative_staff_capability(): void
    {
        $booking = $this->booking(); $case = $this->impact($booking); $this->actor('owner');
        $staff = StaffProfile::create(['business_id' => $booking->business_id, 'branch_id' => $booking->branch_id, 'employee_code' => 'IMPACT-NO-CAP', 'full_name' => 'Nhân viên chưa đủ điều kiện', 'status' => 'ACTIVE', 'is_bookable' => true]);
        $oldStaff = $booking->services()->first()->staff_profile_id;
        $this->resolve($case, ['resolution' => 'REASSIGN', 'replacement_staff_id' => $staff->id, 'reason' => 'Đổi người phục vụ'])->assertUnprocessable();
        $this->assertSame($oldStaff, $booking->services()->first()->staff_profile_id);
        $this->assertSame('PENDING', $case->items()->first()->status);
    }

    public function test_transfer_branch_preserves_booking_item_ids_and_price_snapshots(): void
    {
        $booking = $this->booking(); $case = $this->impact($booking); [$target, $staff] = $this->target($booking); $this->actor('owner');
        $originalItem = $booking->services()->first(); $snapshot = $booking->pricingSnapshot->source_snapshot;
        $this->resolve($case, ['resolution' => 'TRANSFER_BRANCH', 'replacement_branch_id' => $target->id, 'replacement_staff_id' => $staff->id, 'reason' => 'Khách đồng ý sang chi nhánh gần nhất'])->assertOk();
        $this->assertSame($target->id, $booking->fresh()->branch_id);
        $item = BookingService::findOrFail($originalItem->id);
        $this->assertSame($booking->id, $item->booking_id);
        $this->assertSame($target->id, $item->branch_id);
        $this->assertSame($originalItem->price_at_booking, $item->price_at_booking);
        $this->assertSame($originalItem->service_name_snapshot, $item->service_name_snapshot);
        $this->assertSame($snapshot, $booking->pricingSnapshot()->first()->source_snapshot);
    }

    public function test_transfer_rejects_package_reference_and_retains_item_and_entitlement_links(): void
    {
        $booking = $this->booking(); $case = $this->impact($booking); [$target, $staff] = $this->target($booking); $this->actor('owner');
        $item = $booking->services()->first();
        $package = DB::table('treatment_packages')->insertGetId(['business_id' => $booking->business_id, 'name' => 'Impact reference package', 'total_cents' => 100, 'session_count' => 1, 'validity_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $purchase = DB::table('package_purchases')->insertGetId(['treatment_package_id' => $package, 'customer_profile_id' => $booking->customer_profile_id,
            'business_id' => $booking->business_id, 'branch_id' => $booking->branch_id, 'total_cents' => 100, 'paid_cents' => 100,
            'status' => 'ACTIVE', 'pricing_snapshot' => '{}', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
        $entitlement = DB::table('package_session_entitlements')->insertGetId(['package_purchase_id' => $purchase, 'sequence' => 1, 'status' => 'RESERVED', 'booking_id' => $booking->id, 'booking_service_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
        $snapshot = $booking->pricingSnapshot->source_snapshot;
        $this->resolve($case, ['resolution' => 'TRANSFER_BRANCH', 'replacement_branch_id' => $target->id, 'replacement_staff_id' => $staff->id, 'reason' => 'Chuyển lịch có liệu trình'])->assertConflict();
        $this->assertSame($booking->branch_id, $booking->fresh()->branch_id);
        $this->assertSame($item->getAttributes(), BookingService::findOrFail($item->id)->getAttributes());
        $this->assertDatabaseHas('package_session_entitlements', ['id' => $entitlement, 'booking_id' => $booking->id, 'booking_service_id' => $item->id]);
        $this->assertSame($snapshot, $booking->pricingSnapshot()->first()->source_snapshot);
        $this->assertSame('PENDING', $case->items()->first()->status);
    }

    private function booking(int $weeks = 0): Booking
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $staff = StaffProfile::where('branch_id', $branch->id)->firstOrFail();
        $date = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->addWeeks($weeks)->startOfDay()->setTime(10, 0);
        while ($date->dayOfWeek === 0) $date = $date->addDay();
        $this->actor('customer');
        $response = $this->withHeader('Idempotency-Key', 'impact-booking-'.$weeks)->postJson(route('booking.store', $branch), ['branch_service_offering_id' => $offering->id, 'staff_profile_id' => $staff->id, 'start_at' => $date->format('Y-m-d\TH:i')])->assertCreated();
        return Booking::findOrFail($response->json('id') ?? $response->json('booking_id'));
    }

    private function impact(Booking $booking): OperationalImpactCase
    {
        return app(ImpactService::class)->openForBranch($booking->branch, User::where('email', 'owner@beautybook.local')->firstOrFail()->id, 'SUSPEND', 'Chi nhánh cần bảo trì');
    }

    private function actor(string $name): void
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        $this->actingAs($user)->withSession(['workspace_assignment_id' => $user->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->value('id')]);
    }

    private function resolve(OperationalImpactCase $case, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->patchJson(route('impacts.resolve', [$case, $case->items()->firstOrFail()]), $data);
    }

    private function target(Booking $booking): array
    {
        $branch = Branch::where('business_id', $booking->business_id)->whereKeyNot($booking->branch_id)->firstOrFail();
        $branch->update(['status' => 'ACTIVE', 'review_status' => 'APPROVED', 'operational_status' => 'ACTIVE']);
        $staff = StaffProfile::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'employee_code' => 'IMPACT-TARGET', 'full_name' => 'Nhân viên chi nhánh đích', 'status' => 'ACTIVE', 'is_bookable' => true]);
        $staff->offerings()->sync(BranchServiceOffering::where('branch_id', $branch->id)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['business_id' => $branch->business_id, 'branch_id' => $branch->id]])->all());
        return [$branch, $staff];
    }
}
