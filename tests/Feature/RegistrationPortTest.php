<?php

namespace Tests\Feature;

use App\Models\{Booking, Branch, BranchChangeRequest, Business, MediaFile, User};
use App\Services\Beautybook\SourceDistrictCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

class RegistrationPortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Exercise the actual default React/API surface, not just legacy routes.
        $_ENV['BEAUTYBOOK_LEGACY_UI'] = $_SERVER['BEAUTYBOOK_LEGACY_UI'] = 'false';
        putenv('BEAUTYBOOK_LEGACY_UI=false');
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        try { parent::tearDown(); }
        finally {
            $_ENV['BEAUTYBOOK_LEGACY_UI'] = $_SERVER['BEAUTYBOOK_LEGACY_UI'] = 'true';
            putenv('BEAUTYBOOK_LEGACY_UI=true');
        }
    }

    private function actor(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        $assignment = $user->roleAssignments()->firstOrFail();
        $this->actingAs($user)->withHeader('Authorization', 'Bearer registration-test')->withSession([
            'workspace_assignment_id' => $assignment->id,
            'beautybook_access_hash' => hash('sha256', 'registration-test'), 'beautybook_access_expires' => time() + 3600,
        ]);

        return $user;
    }

    private function branch(): Branch
    {
        return Branch::where('slug', 'tan-tao')->firstOrFail();
    }

    private function pdf(Branch $branch, ?int $scopeBranchId = null): MediaFile
    {
        $scopeBranchId ??= $branch->id;
        $path = 'registration/'.bin2hex(random_bytes(8)).'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nregistration test\n%%EOF");

        return MediaFile::create(['disk' => 'local', 'path' => $path, 'original_name' => 'registration.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 40, 'uploaded_by' => User::where('email', 'owner@beautybook.local')->value('id'),
            'business_id' => $branch->business_id, 'branch_id' => $scopeBranchId,
            'entity_type' => 'BRANCH_DOCUMENT', 'entity_id' => $scopeBranchId, 'visibility' => 'PRIVATE']);
    }

    private function proposal(Branch $branch): array
    {
        return ['addressLine' => '123 Đường mới, TP Hồ Chí Minh',
            'districtId' => app(SourceDistrictCatalog::class)->all()[0]['id'], 'ward' => 'Phường mới',
            'registrationType' => 'BUSINESS_LOCATION', 'mediaId' => $this->pdf($branch)->id, 'reason' => 'Chuyển địa điểm'];
    }

    public function test_pending_change_preserves_public_location_and_approval_updates_it_once(): void
    {
        $this->actor('owner@beautybook.local');
        $branch = $this->branch(); $old = $branch->address_line;
        $payload = $this->proposal($branch);
        $change = $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $payload)->assertOk()->assertJsonPath('version', 1)->json();
        $this->assertSame($old, $branch->fresh()->address_line);
        $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $payload)->assertConflict();
        $this->actor('admin@beautybook.local');
        $this->getJson('/api/v1/admin/branch-change-requests')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/media/'.$payload['mediaId'].'/content')->assertOk();
        $url = '/api/v1/admin/branch-change-requests/'.$change['id'].'/review';
        $this->postJson($url, ['decision' => 'APPROVE', 'expectedVersion' => 1])->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('version', 2);
        $this->assertSame($payload['addressLine'], $branch->fresh()->address_line);
        $this->assertDatabaseHas('beautybook_document_versions', ['media_id' => $payload['mediaId']]);
        $this->postJson($url, ['decision' => 'APPROVE', 'expectedVersion' => 1])->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'BranchChangeRequest', 'entity_id' => $change['id'], 'action' => 'BRANCH_CHANGE_APPROVED']);
    }

    public function test_cross_tenant_staff_and_customers_cannot_submit_or_review(): void
    {
        $branch = $this->branch(); $payload = $this->proposal($branch);
        foreach (['owner2@beautybook.local', 'staff@beautybook.local', 'customer@beautybook.local'] as $email) {
            $this->actor($email);
            $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $payload)->assertForbidden();
            $this->getJson('/api/v1/admin/branch-change-requests')->assertForbidden();
        }
        $this->assertSame(0, BranchChangeRequest::count());
    }

    public function test_private_pdf_must_belong_to_branch_and_cannot_be_removed_after_submission(): void
    {
        $this->actor('owner@beautybook.local'); $branch = $this->branch(); $payload = $this->proposal($branch);
        $media = MediaFile::findOrFail($payload['mediaId']);
        $media->update(['visibility' => 'PUBLIC']);
        $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $payload)->assertUnprocessable();
        $media->update(['visibility' => 'PRIVATE', 'branch_id' => null]);
        $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $payload)->assertUnprocessable();
        $media->update(['branch_id' => $branch->id]);
        $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $payload)->assertOk();
        $this->deleteJson('/api/v1/media/'.$media->id)->assertConflict();
    }

    public function test_approved_location_cannot_bypass_review_via_patch_or_onboarding(): void
    {
        $this->actor('owner@beautybook.local'); $branch = $this->branch(); $old = $branch->address_line;
        $this->patchJson('/api/v1/branches/'.$branch->id, ['addressLine' => 'Changed without review'])->assertConflict();
        $this->patchJson('/api/v1/branches/'.$branch->id.'/onboarding', ['branch' => ['addressLine' => 'Changed without review']])->assertConflict();
        $this->assertSame($old, $branch->fresh()->address_line);
        $this->patchJson('/api/v1/branches/'.$branch->id, ['phone' => '0901234567'])->assertOk();
    }

    public function test_withdraw_and_reject_require_current_version_and_keep_existing_location(): void
    {
        $this->actor('owner@beautybook.local'); $branch = $this->branch(); $old = $branch->address_line;
        $proposal = $this->proposal($branch);
        $change = $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $proposal)->assertOk()->json();
        $url = '/api/v1/branches/'.$branch->id.'/change-requests/'.$change['id'].'/withdraw';
        $this->postJson($url, ['expectedVersion' => 2])->assertConflict();
        $this->postJson($url, ['expectedVersion' => 1])->assertOk()->assertJsonPath('status', 'WITHDRAWN');
        $change = $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $proposal)->assertOk()->json();
        $this->actor('admin@beautybook.local');
        $url = '/api/v1/admin/branch-change-requests/'.$change['id'].'/review';
        $this->postJson($url, ['decision' => 'REJECT', 'expectedVersion' => 1])->assertUnprocessable();
        $this->postJson($url, ['decision' => 'REJECT', 'expectedVersion' => 1, 'note' => 'Cần giấy đăng ký mới'])->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertSame($old, $branch->fresh()->address_line);
    }

    public function test_active_bookings_block_relocation_and_old_bookings_keep_observed_location(): void
    {
        $this->actor('owner@beautybook.local'); $branch = $this->branch(); $old = $branch->address_line;
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id,
            'created_by_user_id' => User::where('email', 'owner@beautybook.local')->value('id'),
            'booking_code' => 'REGISTRATION-HISTORY', 'appointment_start_at' => now('UTC')->addDays(2),
            'appointment_end_at' => now('UTC')->addDays(2)->addHour(), 'status' => 'CONFIRMED', 'source' => 'PHONE', 'currency' => 'VND', 'total_amount' => 100000]);
        $this->assertSame($old, $booking->branch_location_snapshot['addressLine']);
        $change = $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $this->proposal($branch))->assertOk()->json();
        $this->actor('admin@beautybook.local');
        $url = '/api/v1/admin/branch-change-requests/'.$change['id'].'/review';
        $this->postJson($url, ['decision' => 'APPROVE', 'expectedVersion' => 1])->assertConflict();
        $booking->update(['status' => 'CANCELLED']);
        $this->postJson($url, ['decision' => 'APPROVE', 'expectedVersion' => 1])->assertOk();
        $this->getJson('/api/v1/bookings/'.$booking->id)->assertOk()->assertJsonPath('branch.address', $old);
        $this->assertSame($old, $booking->fresh()->branch_location_snapshot['addressLine']);
    }

    public function test_drift_is_rejected_instead_of_overwriting_changed_branch(): void
    {
        $this->actor('owner@beautybook.local'); $branch = $this->branch();
        $change = $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $this->proposal($branch))->assertOk()->json();
        $branch->update(['address_line' => 'A different concurrent address']);
        $this->actor('admin@beautybook.local');
        $this->postJson('/api/v1/admin/branch-change-requests/'.$change['id'].'/review', ['decision' => 'APPROVE', 'expectedVersion' => 1])->assertConflict();
        $this->assertSame('PENDING', BranchChangeRequest::find($change['id'])->status);
    }

    public function test_expired_pending_hold_does_not_block_relocation_in_application_timezone(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Ho_Chi_Minh');
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $this->beforeApplicationDestroyed(static fn () => date_default_timezone_set($previousTimezone));
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-03 20:00:00', 'Asia/Ho_Chi_Minh'));
        $this->actor('owner@beautybook.local'); $branch = $this->branch();
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id,
            'created_by_user_id' => User::where('email', 'owner@beautybook.local')->value('id'),
            'booking_code' => 'REGISTRATION-PENDING', 'appointment_start_at' => now('UTC')->addDays(2),
            'appointment_end_at' => now('UTC')->addDays(2)->addHour(), 'status' => 'PENDING',
            'pending_expires_at' => now()->addMinute(), 'source' => 'PHONE', 'currency' => 'VND', 'total_amount' => 100000]);
        $change = $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $this->proposal($branch))->assertOk()->json();
        $this->actor('admin@beautybook.local');
        $url = '/api/v1/admin/branch-change-requests/'.$change['id'].'/review';
        $this->postJson($url, ['decision' => 'APPROVE', 'expectedVersion' => 1])->assertConflict();
        $booking->update(['pending_expires_at' => now()->subMinute()]);
        $this->postJson($url, ['decision' => 'APPROVE', 'expectedVersion' => 1])->assertOk();
    }

    public function test_legacy_booking_snapshot_is_backfilled_with_observed_provenance_even_when_deleted(): void
    {
        $this->actor('owner@beautybook.local'); $branch = $this->branch(); $old = $branch->address_line;
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id,
            'created_by_user_id' => User::where('email', 'owner@beautybook.local')->value('id'),
            'booking_code' => 'REGISTRATION-LEGACY', 'appointment_start_at' => now('UTC')->subDays(2),
            'appointment_end_at' => now('UTC')->subDays(2)->addHour(), 'status' => 'CANCELLED',
            'source' => 'PHONE', 'currency' => 'VND', 'total_amount' => 100000]);
        DB::table('bookings')->where('id', $booking->id)->update(['branch_location_snapshot' => null]);
        $booking->delete();
        $change = $this->postJson('/api/v1/branches/'.$branch->id.'/change-requests', $this->proposal($branch))->assertOk()->json();
        $this->actor('admin@beautybook.local');
        $this->postJson('/api/v1/admin/branch-change-requests/'.$change['id'].'/review', ['decision' => 'APPROVE', 'expectedVersion' => 1])->assertOk();
        $snapshot = Booking::withTrashed()->findOrFail($booking->id)->branch_location_snapshot;
        $this->assertSame($old, $snapshot['addressLine']);
        $this->assertSame('LEGACY_CURRENT_AT_CHANGE', $snapshot['basis']);
        $this->assertSame(0, \Carbon\CarbonImmutable::parse($snapshot['capturedAt'])->offset);
    }

    public function test_legal_registration_roundtrips_and_requires_private_pdf_before_submit(): void
    {
        $this->actor('owner@beautybook.local');
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $business->update(['status' => 'DRAFT']);
        $this->patchJson('/api/v1/business/'.$business->id.'/onboarding', [
            'name' => 'Registered business', 'contactEmail' => 'owner@beautybook.local', 'contactPhone' => '0901234567',
            'addressLine' => '100 Business Road', 'companyName' => 'Registered Company', 'taxCode' => '1234567890',
            'legalEntityType' => 'ENTERPRISE', 'legalName' => 'Registered Company',
            'registrationNumber' => 'REG-1234', 'registeredAddress' => '100 Business Road',
        ])->assertOk()->assertJsonPath('legalEntityType', 'ENTERPRISE')->assertJsonPath('registrationNumber', 'REG-1234');
        // Disable the older optional ID-card policy: the new PDF requirement is independent.
        app(\App\Services\Platform\PlatformSettingsService::class)->update(['requireIdVerification' => false], User::where('email', 'admin@beautybook.local')->value('id'));
        $this->postJson('/api/v1/business/'.$business->id.'/submit')->assertUnprocessable();
        $media = $this->pdf($this->branch());
        $media->update(['branch_id' => null, 'entity_type' => 'BUSINESS_DOCUMENT', 'entity_id' => $business->id]);
        $this->patchJson('/api/v1/business/'.$business->id.'/onboarding', ['legalDocuments' => [[
            'mediaId' => $media->id, 'documentType' => 'BUSINESS_LICENSE', 'documentName' => 'Giấy đăng ký',
        ]]])->assertOk();
        $this->postJson('/api/v1/business/'.$business->id.'/submit')->assertOk()->assertJsonPath('status', 'PENDING_REVIEW');
    }

    public function test_branch_registration_requires_its_own_private_pdf_before_submission(): void
    {
        $this->actor('owner@beautybook.local');
        $branch = $this->branch();
        $branch->update(['review_status' => 'DRAFT', 'registration_type' => 'BUSINESS_LOCATION']);
        $this->patchJson('/api/v1/branches/'.$branch->id, ['districtId' => app(SourceDistrictCatalog::class)->all()[0]['id']])->assertOk();
        $this->postJson('/api/v1/branches/'.$branch->id.'/submit')->assertUnprocessable()->assertJsonPath('message', 'Cần giấy đăng ký chi nhánh PDF riêng tư.');
        $media = $this->pdf($branch);
        $this->postJson('/api/v1/branches/'.$branch->id.'/documents', [
            'mediaId' => $media->id, 'documentType' => 'OPERATING_LICENSE', 'documentName' => 'Đăng ký địa điểm',
        ])->assertOk();
        $this->postJson('/api/v1/branches/'.$branch->id.'/submit')->assertOk()->assertJsonPath('reviewStatus', 'PENDING_REVIEW');
    }
}
