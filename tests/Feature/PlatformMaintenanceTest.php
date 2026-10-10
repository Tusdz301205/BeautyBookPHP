<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\User;
use App\Services\Booking\BookingMaintenanceService;
use App\Services\Booking\BookingNotificationService;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_expiry_is_idempotent_and_does_not_expire_confirmed_bookings(): void
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $data = ['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->customerProfile->id,
            'created_by_user_id' => $customer->id, 'appointment_start_at' => now()->addDay(), 'appointment_end_at' => now()->addDay()->addHour(),
            'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => '100000.00', 'pending_expires_at' => now()->subMinute()];
        $expired = Booking::create([...$data, 'booking_code' => 'MAINT-PENDING', 'status' => 'PENDING']);
        $confirmed = Booking::create([...$data, 'booking_code' => 'MAINT-CONFIRMED', 'status' => 'CONFIRMED']);
        $service = app(BookingMaintenanceService::class);
        $this->assertSame(1, $service->expire());
        $this->assertSame(0, $service->expire());
        $this->assertSame('EXPIRED', $expired->fresh()->status);
        $this->assertSame('CONFIRMED', $confirmed->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 1);
    }

    public function test_outbox_delivery_is_idempotent_and_read_scope_is_private(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        app(BookingNotificationService::class)->record($customer->id, 'TEST', 'Thông báo thử', null, null, 'maintenance-test');
        $service = app(BookingMaintenanceService::class);
        $this->assertSame(1, $service->drainOutbox());
        $this->assertSame(0, $service->drainOutbox());
        $id = DB::table('notifications')->where('dedupe_key', 'maintenance-test')->value('id');
        $this->actingAs($owner)->get(route('notifications.index'))->assertDontSee('Thông báo thử');
        $token = 'maintenance-csrf';
        $this->withSession(['_token' => $token])->postJson(route('notifications.read', $id), [], ['X-CSRF-TOKEN' => $token])->assertNotFound();
        $this->actingAs($customer)->get(route('notifications.index'))->assertSee('Thông báo thử');
        $this->withSession(['_token' => $token])->postJson(route('notifications.read', $id), [], ['X-CSRF-TOKEN' => $token])->assertOk();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'is_read' => true]);
    }

    public function test_admin_settings_validate_and_log_while_owner_cannot_mutate(): void
    {
        $admin = User::where('email', 'admin@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $settings = app(PlatformSettingsService::class);
        $settings->update(['pendingHoldMinutes' => 45], $admin->id);
        $this->assertSame(45, $settings->value('pendingHoldMinutes'));
        $this->assertSame(false, $settings->value('allowAnonymousReview'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'entity_type' => 'PlatformSetting']);
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $owner->roleAssignments()->firstOrFail()->id])
            ->get(route('platform.settings'))->assertForbidden();
        $this->actingAs($admin)->withSession(['workspace_assignment_id' => $admin->roleAssignments()->firstOrFail()->id])
            ->get(route('platform.settings'))->assertOk();
        $token = 'platform-csrf';
        $this->withSession(['_token' => $token])->putJson(route('platform.settings.update'), ['settings' => ['freeCancellationHours' => 3]], ['X-CSRF-TOKEN' => $token])->assertUnprocessable();
        $this->assertSame(4, $settings->value('freeCancellationHours'));
    }
}
