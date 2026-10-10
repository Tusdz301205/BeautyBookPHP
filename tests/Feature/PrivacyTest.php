<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_export_requires_password_and_has_one_time_customer_scoped_download(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $this->actingAs($customer);
        $token = 'privacy-csrf';
        $this->withSession(['_token' => $token])->postJson(route('privacy.export'), ['current_password' => 'wrong'], ['X-CSRF-TOKEN' => $token])->assertForbidden();
        $response = $this->withSession(['_token' => $token])->postJson(route('privacy.export'), ['current_password' => 'BeautyBook123!'], ['X-CSRF-TOKEN' => $token])->assertCreated();
        $package = DB::table('privacy_export_packages')->first();
        $this->assertStringNotContainsString($customer->email, $package->payload_ciphertext);
        $this->assertNotSame($response->json('download_token'), $package->download_token_hash);
        $this->actingAs($owner)->withSession(['_token' => $token])->postJson(route('privacy.download', $package->id), ['download_token' => $response->json('download_token')], ['X-CSRF-TOKEN' => $token])->assertForbidden();
        $this->actingAs($customer);
        $download = $this->withSession(['_token' => $token])->postJson(route('privacy.download', $package->id), ['download_token' => $response->json('download_token')], ['X-CSRF-TOKEN' => $token])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($customer->email, $download->json('account.email'));
        $this->assertArrayNotHasKey('password', $download->json('account'));
        $this->withSession(['_token' => $token])->postJson(route('privacy.download', $package->id), ['download_token' => $response->json('download_token')], ['X-CSRF-TOKEN' => $token])->assertNotFound();
    }

    public function test_data_request_duplicate_is_serialized_and_expired_packages_are_cleaned(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $this->actingAs($customer);
        $token = 'privacy-csrf';
        for ($i = 0; $i < 2; $i++) $this->withSession(['_token' => $token])->postJson(route('privacy.requests'), ['type' => 'ERASURE', 'reason' => 'Dữ liệu thử'], ['X-CSRF-TOKEN' => $token])->assertCreated();
        $this->assertDatabaseCount('data_subject_requests', 1);
        $this->get(route('privacy.index'))->assertOk()->assertSee('ERASURE');
        $service = app(\App\Services\Privacy\PrivacyService::class);
        $request = \Illuminate\Http\Request::create('/privacy');
        $request->setUserResolver(fn () => $customer);
        $package = $service->export($request, 'BeautyBook123!');
        DB::table('privacy_export_packages')->where('id', $package['id'])->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $service->cleanup());
    }

    public function test_export_includes_self_booking_finance_and_review_details_without_foreign_data(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $other = User::create(['name' => 'Foreign Customer', 'email' => 'foreign-export@example.test', 'password' => 'password', 'account_kind' => 'CUSTOMER']);
        $otherProfile = \App\Models\CustomerProfile::create(['user_id' => $other->id]);
        $branch = \App\Models\Branch::where('slug', 'tan-tao')->firstOrFail();
        foreach ([$customer->customerProfile->id, $otherProfile->id] as $profileId) {
            $booking = \App\Models\Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $profileId,
                'created_by_user_id' => $customer->id, 'booking_code' => 'EXPORT-'.$profileId, 'appointment_start_at' => now('UTC')->subDay(),
                'appointment_end_at' => now('UTC')->subDay()->addHour(), 'status' => 'COMPLETED', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => '100.00']);
            $payment = \App\Models\PaymentTransaction::create(['booking_id' => $booking->id, 'amount_cents' => 10000, 'currency' => 'VND', 'method' => 'CASH', 'status' => 'VERIFIED', 'idempotency_key' => 'EXPORT-PAY-'.$profileId, 'created_by' => $customer->id, 'evidence' => ['internal' => 'NEVER_EXPORT_INTERNAL_EVIDENCE']]);
            DB::table('refund_requests')->insert(['payment_transaction_id' => $payment->id, 'amount_cents' => 1000, 'reason' => 'Customer request', 'status' => 'REFUNDED', 'requested_by' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
            \App\Models\BookingStatusHistory::create(['booking_id' => $booking->id, 'actor_user_id' => $customer->id, 'from_status' => 'IN_PROGRESS', 'to_status' => 'COMPLETED']);
            \App\Models\Review::create(['booking_id' => $booking->id, 'customer_profile_id' => $profileId, 'overall_rating' => 5, 'comment' => 'Own review details', 'is_anonymous' => true, 'service_ratings' => [], 'status' => 'APPROVED']);
        }
        $request = \Illuminate\Http\Request::create('/privacy');
        $request->setUserResolver(fn () => $customer);
        $service = app(\App\Services\Privacy\PrivacyService::class);
        $package = $service->export($request, 'BeautyBook123!');
        $raw = $service->download($request, $package['id'], $package['download_token']);
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $payload['bookings']);
        $this->assertCount(1, $payload['payments']);
        $this->assertCount(1, $payload['refunds']);
        $this->assertSame('BeautyBook Demo', $payload['bookings'][0]['branch']['business']['name']);
        $this->assertSame('COMPLETED', $payload['bookings'][0]['status_history'][0]['to_status']);
        $this->assertSame(1, $payload['reviews'][0]['is_anonymous']);
        $this->assertStringNotContainsString('EXPORT-'.$otherProfile->id, $raw);
        $this->assertStringNotContainsString('NEVER_EXPORT_INTERNAL_EVIDENCE', $raw);
    }
}
