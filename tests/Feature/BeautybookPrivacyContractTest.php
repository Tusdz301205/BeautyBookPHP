<?php

namespace Tests\Feature;

use App\Models\{Booking, Branch, CustomerProfile, PaymentTransaction, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BeautybookPrivacyContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function login(User $user): void
    {
        $this->actingAs($user)->withSession(['workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id, '_token' => 'privacy-source-csrf', 'beautybook_access_hash' => hash('sha256', 'privacy-source-access'), 'beautybook_access_expires' => time() + 900])->withHeaders(['Authorization' => 'Bearer privacy-source-access', 'X-CSRF-TOKEN' => 'privacy-source-csrf']);
    }

    public function test_duplicate_open_request_matches_source_notice_without_creating_another_row(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $this->login($customer);
        $first = $this->postJson('/api/v1/privacy/data-requests', ['type' => 'ERASURE', 'reason' => 'First'])->assertCreated();
        $this->postJson('/api/v1/privacy/data-requests', ['type' => 'ERASURE', 'reason' => 'Repeated'])->assertCreated()->assertJsonPath('id', $first->json('id'))->assertJsonPath('duplicate', true)->assertJsonPath('reason', 'First');
        $this->assertDatabaseCount('data_subject_requests', 1);
        $this->assertArrayNotHasKey('duplicate', (array) DB::table('data_subject_requests')->first());
    }

    public function test_source_export_contains_actual_self_profile_nested_finance_and_no_foreign_records(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        DB::table('beautybook_user_profiles')->updateOrInsert(['user_id' => $customer->id], ['phone' => '0901234567', 'gender' => 'FEMALE', 'date_of_birth' => '2000-01-02', 'address' => 'Own export address', 'updated_at' => now()]);
        DB::table('marketing_preferences')->insert(['customer_profile_id' => $customer->customerProfile->id, 'email_marketing' => true, 'sms_marketing' => false, 'push_marketing' => true, 'personalized_promotions' => false, 'created_at' => now(), 'updated_at' => now()]);
        $foreign = User::create(['name' => 'Foreign', 'email' => 'private-foreign@example.test', 'password' => 'password', 'account_kind' => 'CUSTOMER']);
        $foreignProfile = CustomerProfile::create(['user_id' => $foreign->id]);
        \App\Models\RoleAssignment::create(['user_id' => $foreign->id, 'role_name' => 'CUSTOMER', 'scope_key' => 'self']);
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = \App\Models\BranchServiceOffering::where('branch_id', $branch->id)->firstOrFail();
        foreach ([$customer->customerProfile, $foreignProfile] as $profile) {
            $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $profile->id, 'created_by_user_id' => $customer->id, 'booking_code' => 'SOURCE-EXPORT-'.$profile->id, 'appointment_start_at' => now()->utc()->subDay(), 'appointment_end_at' => now()->utc()->subDay()->addHour(), 'status' => 'COMPLETED', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 100]);
            \App\Models\BookingService::create(['booking_id' => $booking->id, 'business_id' => $branch->business_id, 'branch_id' => $branch->id, 'branch_service_offering_id' => $offering->id, 'business_service_id' => $offering->business_service_id, 'price_at_booking' => 100, 'duration_minutes' => 60, 'service_name_snapshot' => 'Own booked service', 'item_start_at' => $booking->appointment_start_at, 'item_end_at' => $booking->appointment_end_at, 'occupied_start_at' => $booking->appointment_start_at, 'occupied_end_at' => $booking->appointment_end_at]);
            \App\Models\BookingStatusHistory::create(['booking_id' => $booking->id, 'from_status' => 'IN_PROGRESS', 'to_status' => 'COMPLETED', 'reason' => 'Actual completion']);
            $payment = PaymentTransaction::create(['booking_id' => $booking->id, 'amount_cents' => 10000, 'currency' => 'VND', 'method' => 'CASH', 'status' => 'VERIFIED', 'idempotency_key' => 'SOURCE-EXPORT-PAY-'.$profile->id, 'created_by' => $customer->id, 'evidence' => ['secret' => 'INTERNAL_PAYMENT_EVIDENCE']]);
            DB::table('refund_requests')->insert(['payment_transaction_id' => $payment->id, 'amount_cents' => 1000, 'reason' => 'Own refund reason', 'status' => 'REFUNDED', 'requested_by' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->login($customer);
        $this->postJson('/api/v1/privacy/exports', ['currentPassword' => 'wrong'])->assertForbidden();
        $export = $this->postJson('/api/v1/privacy/exports', ['currentPassword' => 'BeautyBook123!'])->assertCreated();
        $id = $export->json('id'); $headers = ['X-Privacy-Download-Token' => $export->json('downloadToken')];
        $this->login($foreign);
        $this->getJson('/api/v1/privacy/exports/'.$id.'/download', $headers)->assertNotFound();
        $this->login($customer);
        $payload = $this->getJson('/api/v1/privacy/exports/'.$id.'/download', $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('formatVersion', 'beautybook-privacy-export-v1')->assertJsonPath('profile.email', $customer->email)->assertJsonPath('profile.phone', '0901234567')->assertJsonPath('profile.gender', 'FEMALE')->assertJsonPath('profile.address', 'Own export address')->assertJsonPath('profile.dateOfBirth', '2000-01-02')->assertJsonPath('marketingPreferences.emailMarketing', true)->assertJsonCount(1, 'bookings')->assertJsonPath('bookings.0.payments.0.amount', 100)->assertJsonPath('bookings.0.payments.0.refundRequests.0.amount', 10)->assertJsonPath('bookings.0.payments.0.refundRequests.0.reason', 'Own refund reason')->assertJsonStructure(['generatedAt', 'dataSubjectRequests', 'notifications', 'reviews', 'bookings' => [['bookingCode', 'bookingServices', 'statusHistory']]]);
        $this->assertStringNotContainsString('SOURCE-EXPORT-'.$foreignProfile->id, $payload->getContent());
        $payload->assertJsonPath('bookings.0.bookingServices.0.service.name', 'Own booked service')->assertJsonPath('bookings.0.bookingServices.0.serviceId', (string) $offering->id)->assertJsonPath('bookings.0.statusHistory.0.note', 'Actual completion');
        $this->assertStringNotContainsString('INTERNAL_PAYMENT_EVIDENCE', $payload->getContent());
        $this->assertStringNotContainsString($customer->email, DB::table('privacy_export_packages')->find($id)->payload_ciphertext);
        $this->getJson('/api/v1/privacy/exports/'.$id.'/download', $headers)->assertNotFound();
    }
}
