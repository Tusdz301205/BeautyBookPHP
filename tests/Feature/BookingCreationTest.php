<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\ServiceVariant;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Catalog\CatalogManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BookingCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_day_slot_picker_excludes_an_existing_booking_and_keeps_branch_timezone(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = $this->openStart(12);
        $this->actingAs($customer);
        $url = route('booking.slots', $branch).'?'.http_build_query([
            'date' => substr($start, 0, 10), 'branch_service_offering_id' => $offering->id, 'staff_profile_id' => $staff->id,
        ]);
        $before = $this->getJson($url)->assertOk()->assertJsonPath('timezone', $branch->timezone);
        $this->assertContains($start, array_column($before->json('slots'), 'value'));
        $this->assertLessThanOrEqual(96, count($before->json('slots')));
        $this->postJsonWithCsrf(route('booking.store', $branch), $this->payload($offering, $staff, $start), 'slot-ui-booking')->assertCreated();
        $after = $this->getJson($url)->assertOk();
        $this->assertNotContains($start, array_column($after->json('slots'), 'value'));
    }

    public function test_slot_picker_rejects_staff_from_another_branch_and_past_days(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $otherBranch = Branch::whereKeyNot($branch->id)->firstOrFail();
        $otherStaff = StaffProfile::create([
            'business_id' => $otherBranch->business_id, 'branch_id' => $otherBranch->id,
            'full_name' => 'Chuyên viên cơ sở khác', 'employee_code' => 'SLOT-SCOPE-TEST',
            'status' => 'ACTIVE', 'is_bookable' => true, 'public_visible' => true,
        ]);
        $this->actingAs($customer);
        $params = ['date' => substr($this->openStart(12), 0, 10), 'branch_service_offering_id' => $offering->id, 'staff_profile_id' => $otherStaff->id];
        $this->getJson(route('booking.slots', $branch).'?'.http_build_query($params))->assertUnprocessable();
        $params['staff_profile_id'] = $staff->id;
        $params['date'] = CarbonImmutable::now($branch->timezone)->subDay()->format('Y-m-d');
        $this->getJson(route('booking.slots', $branch).'?'.http_build_query($params))->assertUnprocessable();
    }

    public function test_customer_booking_creates_profile_link_snapshots_and_history(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = $this->openStart(10);

        $this->actingAs($customer);
        $response = $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($offering, $staff, $start),
            'customer-create-1',
        );

        $response->assertCreated()
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('replayed', false);

        $booking = Booking::query()->with(['services', 'pricingSnapshot', 'statusHistory'])->firstOrFail();

        $this->assertSame($customer->customerProfile->id, $booking->customer_profile_id);
        $this->assertSame('ONLINE_WEB', $booking->source);
        $this->assertNull($booking->contact);
        $this->assertCount(1, $booking->services);
        $this->assertSame((string) $offering->price, $booking->services->first()->price_at_booking);
        $this->assertSame((string) $offering->price, $booking->pricingSnapshot->final_amount);
        $this->assertSame('PENDING', $booking->statusHistory->first()->to_status);
    }

    public function test_operational_booking_uses_contact_without_linking_customer_profile(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $payload = $this->payload($offering, $staff, $this->openStart(11));
        $payload['contact'] = [
            'full_name' => 'Khách tại quầy',
            'phone' => '0909000111',
            'email' => null,
        ];

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->postJsonWithCsrf(route('booking.store', $branch), $payload, 'owner-create-1')
            ->assertCreated();

        $booking = Booking::query()->with('contact')->firstOrFail();

        $this->assertNull($booking->customer_profile_id);
        $this->assertSame('STAFF_CREATED', $booking->source);
        $this->assertSame('Khách tại quầy', $booking->contact->full_name);
        $this->assertSame('0909000111', $booking->contact->phone);
    }

    public function test_operational_booking_requires_contact_and_receptionist_requires_supported_source(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $ownerAssignment->id]);
        $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($offering, $staff, $this->openStart(11)),
            'owner-missing-contact',
        )
            ->assertUnprocessable();

        $receptionist = User::where('email', 'receptionist@beautybook.local')->firstOrFail();
        $receptionistAssignment = $receptionist->roleAssignments()->where('role_name', 'RECEPTIONIST')->firstOrFail();
        $payload = $this->payload($offering, $staff, $this->openStart(12));
        $payload['source'] = 'STAFF_CREATED';
        $payload['contact'] = ['full_name' => 'Khách thử nguồn', 'phone' => '0909000333'];

        $this->actingAs($receptionist)->withSession(['workspace_assignment_id' => $receptionistAssignment->id]);
        $this->postJsonWithCsrf(route('booking.store', $branch), $payload, 'receptionist-invalid-source')
            ->assertUnprocessable();

        $this->assertSame(0, Booking::count());
    }

    public function test_receptionist_is_scoped_to_exact_branch_and_guest_is_denied(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $otherBranch = Branch::where('business_id', $branch->business_id)->whereKeyNot($branch->id)->firstOrFail();
        $receptionist = User::where('email', 'receptionist@beautybook.local')->firstOrFail();
        $assignment = $receptionist->roleAssignments()->where('role_name', 'RECEPTIONIST')->firstOrFail();
        $payload = $this->payload($offering, $staff, $this->openStart(12));
        $payload['source'] = 'PHONE';
        $payload['contact'] = ['full_name' => 'Khách gọi điện', 'phone' => '0909000222', 'email' => null];

        $this->actingAs($receptionist)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->postJsonWithCsrf(route('booking.store', $branch), $payload, 'receptionist-create-1')
            ->assertCreated();

        $this->actingAs($receptionist)
            ->withSession(['workspace_assignment_id' => $assignment->id])
            ->get(route('booking.create', $otherBranch))
            ->assertForbidden();

        auth()->logout();
        $this->get(route('booking.create', $branch))->assertRedirect(route('login'));
    }

    public function test_same_idempotency_key_replays_same_payload_and_rejects_different_payload(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $payload = $this->payload($offering, $staff, $this->openStart(10));

        $this->actingAs($customer);
        $first = $this->postJsonWithCsrf(route('booking.store', $branch), $payload, 'idem-replay-1')
            ->assertCreated();

        $second = $this->postJsonWithCsrf(route('booking.store', $branch), $payload, 'idem-replay-1')
            ->assertCreated()
            ->assertJsonPath('replayed', true);

        $this->assertSame($first->json('booking_id'), $second->json('booking_id'));
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, DB::table('idempotency_records')->count());

        $changed = $payload;
        $changed['start_at'] = $this->openStart(12);

        $this->postJsonWithCsrf(route('booking.store', $branch), $changed, 'idem-replay-1')
            ->assertConflict();

        $this->assertSame(1, Booking::count());
    }

    public function test_half_open_adjacency_is_allowed_but_overlap_is_rejected(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();

        $this->actingAs($customer);
        $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($offering, $staff, $this->openStart(10)),
            'adjacent-1',
        )
            ->assertCreated();

        $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($offering, $staff, $this->openStart(11)),
            'adjacent-2',
        )
            ->assertCreated();

        $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($offering, $staff, $this->openStart(10, 30)),
            'overlap-1',
        )
            ->assertUnprocessable();

        $this->assertSame(2, Booking::count());
    }

    public function test_same_customer_cannot_overlap_across_branches(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $otherBranch = Branch::where('business_id', $branch->business_id)->whereKeyNot($branch->id)->firstOrFail();
        $otherOffering = BranchServiceOffering::where('branch_id', $otherBranch->id)
            ->where('business_service_id', $offering->business_service_id)
            ->firstOrFail();
        $otherStaff = StaffProfile::create([
            'business_id' => $otherBranch->business_id,
            'branch_id' => $otherBranch->id,
            'full_name' => 'Chuyên viên chi nhánh 2',
            'employee_code' => 'BR2-001',
            'public_visible' => true,
            'is_bookable' => true,
            'status' => 'ACTIVE',
        ]);
        DB::table('staff_services')->insert([
            'staff_profile_id' => $otherStaff->id,
            'branch_service_offering_id' => $otherOffering->id,
            'business_id' => $otherBranch->business_id,
            'branch_id' => $otherBranch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $start = $this->openStart(13);

        $this->actingAs($customer);
        $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($offering, $staff, $start),
            'cross-branch-1',
        )
            ->assertCreated();

        $this->postJsonWithCsrf(
            route('booking.store', $otherBranch),
            $this->payload($otherOffering, $otherStaff, $start),
            'cross-branch-2',
        )
            ->assertUnprocessable();

        $this->assertSame(1, Booking::count());
    }

    public function test_cross_branch_offering_and_staff_tampering_are_rejected(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $otherBranch = Branch::where('business_id', $branch->business_id)->whereKeyNot($branch->id)->firstOrFail();
        $otherOffering = BranchServiceOffering::where('branch_id', $otherBranch->id)->orderBy('id')->firstOrFail();
        $otherStaff = StaffProfile::create([
            'business_id' => $otherBranch->business_id,
            'branch_id' => $otherBranch->id,
            'full_name' => 'Nhân viên chi nhánh khác',
            'employee_code' => 'BR2-TAMPER',
            'public_visible' => true,
            'is_bookable' => true,
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($customer);
        $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($otherOffering, $staff, $this->openStart(13)),
            'wrong-offering-branch',
        )
            ->assertUnprocessable();

        $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($offering, $otherStaff, $this->openStart(14)),
            'wrong-staff-branch',
        )
            ->assertUnprocessable();

        $this->assertSame(0, Booking::count());
    }

    public function test_operational_booking_is_blocked_when_branch_is_paused(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $branch->update(['operational_status' => 'PAUSED']);
        $payload = $this->payload($offering, $staff, $this->openStart(15));
        $payload['contact'] = ['full_name' => 'Khách tạm dừng', 'phone' => '0909000444'];

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->postJsonWithCsrf(route('booking.store', $branch), $payload, 'paused-branch')
            ->assertConflict();

        $this->assertSame(0, Booking::count());
    }

    public function test_fixed_variant_controls_snapshot_price_duration_and_buffers(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $variant = app(CatalogManagementService::class)->createVariant($offering, [
            'code' => 'FIXED-BOOKING',
            'name' => 'Gói cố định',
            'price_type' => 'FIXED',
            'price' => '275000.00',
            'max_price' => null,
            'duration_minutes' => 45,
            'max_duration_minutes' => null,
            'buffer_before_minutes' => 10,
            'buffer_after_minutes' => 15,
            'consultation_required' => false,
            'status' => 'ACTIVE',
        ]);
        $payload = $this->payload($offering, $staff, $this->openStart(14));
        $payload['service_variant_id'] = $variant->id;

        $this->actingAs($customer);
        $this->postJsonWithCsrf(route('booking.store', $branch), $payload, 'fixed-variant-1')
            ->assertCreated();

        $service = Booking::firstOrFail()->services()->firstOrFail();
        $this->assertSame('275000.00', $service->price_at_booking);
        $this->assertSame(45, $service->duration_minutes);
        $this->assertSame(10, $service->buffer_before_minutes);
        $this->assertSame(15, $service->buffer_after_minutes);
        $this->assertSame('Gói cố định', $service->variant_name_snapshot);
    }

    public function test_non_deterministic_variant_types_are_rejected_at_quote(): void
    {
        [$branch, $offering] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $catalog = app(CatalogManagementService::class);
        $variants = [
            $catalog->createVariant($offering, $this->variantData('FROM-BOOKING', 'FROM', '200000.00', null)),
            $catalog->createVariant($offering, $this->variantData('RANGE-BOOKING', 'RANGE', '200000.00', '300000.00')),
            $catalog->createVariant($offering, $this->variantData('QUOTE-BOOKING', 'QUOTE', null, null)),
        ];

        foreach ($variants as $variant) {
            $this->actingAs($customer);
            $this->postJsonWithCsrf(route('booking.quote', $branch), [
                'branch_service_offering_id' => $offering->id,
                'service_variant_id' => $variant->id,
            ])
                ->assertUnprocessable();
        }
    }

    public function test_booking_snapshot_does_not_change_after_catalog_edit(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $originalPrice = (string) $offering->price;
        $originalName = $offering->service->name;

        $this->actingAs($customer);
        $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($offering, $staff, $this->openStart(15)),
            'snapshot-immutable-1',
        )
            ->assertCreated();

        $offering->update(['price' => '999000.00', 'duration_minutes' => 120]);
        $offering->service->update(['name' => 'Tên đã đổi']);

        $booking = Booking::with(['services', 'pricingSnapshot'])->firstOrFail();
        $this->assertSame($originalPrice, $booking->services->first()->price_at_booking);
        $this->assertSame($originalName, $booking->services->first()->service_name_snapshot);
        $this->assertSame($originalPrice, $booking->pricingSnapshot->final_amount);
    }

    public function test_staff_without_capability_is_rejected(): void
    {
        [$branch, $offering] = $this->fixture();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $staff = StaffProfile::create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'full_name' => 'Không có capability',
            'employee_code' => 'NO-CAP-1',
            'public_visible' => true,
            'is_bookable' => true,
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($customer);
        $this->postJsonWithCsrf(
            route('booking.store', $branch),
            $this->payload($offering, $staff, $this->openStart(16)),
            'no-capability-1',
        )
            ->assertUnprocessable();

        $this->assertSame(0, Booking::count());
    }

    private function fixture(): array
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)
            ->with('service')
            ->orderBy('id')
            ->firstOrFail();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail();

        return [$branch, $offering, $staff];
    }

    private function payload(BranchServiceOffering $offering, StaffProfile $staff, string $start): array
    {
        return [
            'branch_service_offering_id' => $offering->id,
            'service_variant_id' => null,
            'staff_profile_id' => $staff->id,
            'start_at' => $start,
        ];
    }

    private function postJsonWithCsrf(string $uri, array $data, ?string $idempotencyKey = null): TestResponse
    {
        $token = 'beautybook-test-csrf-token';
        $headers = ['X-CSRF-TOKEN' => $token];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->withSession(['_token' => $token])->withHeaders($headers)->postJson($uri, $data);
    }

    private function openStart(int $hour, int $minute = 0): string
    {
        $time = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTime($hour, $minute);

        while ($time->dayOfWeek === 0) {
            $time = $time->addDay();
        }

        return $time->format('Y-m-d\TH:i');
    }

    private function variantData(string $code, string $type, ?string $price, ?string $maxPrice): array
    {
        return [
            'code' => $code,
            'name' => $code,
            'price_type' => $type,
            'price' => $price,
            'max_price' => $maxPrice,
            'duration_minutes' => 60,
            'max_duration_minutes' => $type === 'RANGE' ? 90 : null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'consultation_required' => $type === 'QUOTE',
            'status' => 'ACTIVE',
        ];
    }
}
