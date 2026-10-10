<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnershipTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function actor(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user)->withSession(['workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id, '_token' => 'ownership-csrf']);
        $this->withHeaders(['X-CSRF-TOKEN' => 'ownership-csrf']);
        return $user;
    }

    private function approvedTransfer(): int
    {
        $this->actor('owner@beautybook.local');
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $id = $this->postJson(route('ownership.store', $business), ['new_owner_email' => 'owner2@beautybook.local', 'effective_at' => now()->addMinute()->toIso8601String(), 'reason' => 'Bàn giao demo'])->assertCreated()->json('id');
        $legal = $this->postJson(route('ownership.identity', $business), ['type' => 'LEGAL_ENTITY', 'legal_name' => 'Cơ sở Demo', 'tax_code' => 'DEMO-123'])->assertCreated()->json('id');
        $payout = $this->postJson(route('ownership.identity', $business), ['type' => 'PAYOUT_ACCOUNT', 'bank_name' => 'Ngân hàng Demo', 'account_holder' => 'Chủ mới', 'account_number' => '1234567890'])->assertCreated()->json('id');
        $this->actor('owner2@beautybook.local');
        $this->get(route('ownership.index'))->assertOk()->assertSee('Bàn giao demo');
        $this->postJson(route('ownership.action', [$id, 'accept']))->assertOk()->assertJsonPath('status', 'UNDER_REVIEW');
        $this->actor('admin@beautybook.local');
        foreach ([$legal, $payout] as $version) $this->postJson(route('ownership.verify', $version), ['approve' => true, 'reason' => 'Đã kiểm tra fixture'])->assertOk();
        $this->postJson(route('ownership.action', [$id, 'review']), ['decision' => 'APPROVE', 'reason' => 'Hồ sơ đủ điều kiện'])->assertOk()->assertJsonPath('status', 'SCHEDULED');
        $this->travel(2)->minutes();
        return (int)$id;
    }

    public function test_transfer_requires_recipient_acceptance_and_verified_versions_and_revokes_old_owner(): void
    {
        $id = $this->approvedTransfer();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $oldOwner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        DB::table('sessions')->insert(['id' => 'old-owner-session', 'user_id' => $oldOwner->id, 'payload' => '', 'last_activity' => time()]);
        $this->postJson(route('ownership.action', [$id, 'execute']))->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->postJson(route('ownership.action', [$id, 'execute']))->assertOk();
        $this->assertSame(User::where('email', 'owner2@beautybook.local')->firstOrFail()->id, $business->fresh()->ownerProfile->user_id);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-owner-session']);
        $this->assertDatabaseMissing('role_assignments', ['user_id' => $oldOwner->id, 'business_id' => $business->id, 'role_name' => 'BUSINESS_OWNER']);
        $this->assertDatabaseCount('ownership_history', 2);
        $this->assertDatabaseHas('business_identity_versions', ['type' => 'PAYOUT_ACCOUNT', 'is_active' => true]);
        $this->assertStringNotContainsString('1234567890', DB::table('business_identity_versions')->where('type', 'PAYOUT_ACCOUNT')->value('payload_ciphertext'));
        $this->actingAs($oldOwner)->withSession(['workspace_assignment_id' => 999999])->get(route('owner.businesses.edit', $business))->assertForbidden();
    }

    public function test_pending_financial_obligation_blocks_execution(): void
    {
        $id = $this->approvedTransfer();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $branch = $business->branches()->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $booking = \App\Models\Booking::create(['business_id' => $business->id, 'branch_id' => $branch->id, 'created_by_user_id' => $owner->id, 'booking_code' => 'OWNERSHIP-LIABILITY', 'appointment_start_at' => now()->addDay(), 'appointment_end_at' => now()->addDay()->addHour(), 'status' => 'CONFIRMED', 'source' => 'STAFF_CREATED', 'currency' => 'VND', 'total_amount' => '100000.00']);
        \App\Models\PaymentTransaction::create(['booking_id' => $booking->id, 'amount_cents' => 10000000, 'currency' => 'VND', 'method' => 'BANK_TRANSFER', 'status' => 'PENDING', 'idempotency_key' => 'ownership-obligation', 'created_by' => $owner->id]);
        $this->postJson(route('ownership.action', [$id, 'execute']))->assertConflict();
        $this->assertSame($owner->id, $business->fresh()->ownerProfile->user_id);
        $this->assertDatabaseHas('ownership_transfers', ['id' => $id, 'status' => 'SCHEDULED']);
    }

    public function test_customer_cannot_receive_transfer_and_owner_cannot_platform_review(): void
    {
        $this->actor('owner@beautybook.local');
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $data = ['new_owner_email' => 'customer@beautybook.local', 'effective_at' => now()->addDay()->toIso8601String(), 'reason' => 'Demo'];
        $this->postJson(route('ownership.store', $business), $data)->assertUnprocessable();
        $data['new_owner_email'] = 'owner2@beautybook.local';
        $id = $this->postJson(route('ownership.store', $business), $data)->assertCreated()->json('id');
        $this->postJson(route('ownership.store', $business), $data)->assertConflict();
        $this->postJson(route('ownership.action', [$id, 'review']), ['decision' => 'APPROVE', 'reason' => 'Không đủ quyền'])->assertForbidden();
        $this->actor('customer@beautybook.local');
        $this->get(route('ownership.index'))->assertForbidden();
    }
}
