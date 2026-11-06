<?php

namespace App\Services\Branches;

use App\Exceptions\BookingConflictException;
use App\Models\{Booking, Branch, BranchChangeRequest, Business, MediaFile};
use App\Services\Beautybook\ApiScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchChangeService
{
    public const TYPES = ['BUSINESS_LOCATION', 'ENTERPRISE_BRANCH', 'EXISTING_REGISTERED_ADDRESS'];

    public function __construct(private ApiScope $scope) {}

    private function lockedBranch(Request $request, int $id, bool $admin = false): Branch
    {
        // Match the existing booking creation order: branch -> business.
        $branch = Branch::whereKey($id)->lockForUpdate()->firstOrFail();
        $business = Business::whereKey($branch->business_id)->lockForUpdate()->firstOrFail();
        if ($admin) $this->scope->platform($request);
        else $this->scope->owner($request, $business->id);
        $branch->setRelation('business', $business);

        return $branch;
    }

    private function assertEligible(Branch $branch): void
    {
        $business = $branch->business;
        abort_unless($branch->review_status->value === 'APPROVED', 409, 'Chi nhánh phải được duyệt trước khi gửi thay đổi.');
        abort_unless(in_array($business->status->value, ['APPROVED', 'ACTIVE'], true) && ! $business->booking_restricted_at, 409, 'Doanh nghiệp không còn đủ điều kiện thay đổi hồ sơ.');
        abort_if(in_array($branch->operational_status->value, ['CLOSED', 'ARCHIVED', 'SUSPENDED'], true), 409, 'Chi nhánh không còn đủ điều kiện thay đổi hồ sơ.');
    }

    private function evidence(Branch $branch, int $id): void
    {
        $media = MediaFile::whereKey($id)->lockForUpdate()->first();
        abort_unless($media && (int) $media->business_id === $branch->business_id && (int) $media->branch_id === $branch->id
            && (int) $media->entity_id === $branch->id && $media->visibility === 'PRIVATE' && $media->mime_type === 'application/pdf' && $media->entity_type === 'BRANCH_DOCUMENT', 422, 'Cần PDF riêng tư thuộc đúng chi nhánh.');
    }

    public function create(Request $request, int $branchId, array $data): BranchChangeRequest
    {
        return DB::transaction(function () use ($request, $branchId, $data) {
            $branch = $this->lockedBranch($request, $branchId);
            $this->assertEligible($branch);
            if (BranchChangeRequest::where('branch_id', $branchId)->where('status', 'PENDING')->exists()) {
                throw new BookingConflictException('Chi nhánh đã có yêu cầu đang chờ duyệt.');
            }
            $this->evidence($branch, $data['mediaId']);
            abort_if($data['registrationType'] === 'ENTERPRISE_BRANCH' && $branch->business->legal_entity_type === 'HOUSEHOLD_BUSINESS', 422, 'Hộ kinh doanh không dùng loại chi nhánh doanh nghiệp.');
            $base = BranchLocation::snapshot($branch);
            $proposed = ['addressLine' => trim($data['addressLine']), 'districtId' => $data['districtId'], 'ward' => $data['ward'] ?? null, 'registrationType' => $data['registrationType']];
            abort_if(BranchLocation::same($base, $proposed), 422, 'Thông tin đề xuất chưa thay đổi.');
            $change = BranchChangeRequest::create([
                'branch_id' => $branchId, 'requested_by_user_id' => $request->user()->id,
                'media_id' => $data['mediaId'], 'base_snapshot' => $base, 'proposed_snapshot' => $proposed,
                'reason' => $data['reason'] ?? null, 'status' => 'PENDING', 'version' => 1,
            ]);
            $this->audit($request, $branch, $change, 'BRANCH_CHANGE_SUBMITTED');

            return $change;
        }, 3);
    }

    public function decide(Request $request, int $branchId, int $id, int $version, string $action, ?string $note = null): BranchChangeRequest
    {
        return DB::transaction(function () use ($request, $branchId, $id, $version, $action, $note) {
            $branch = $this->lockedBranch($request, $branchId, $action !== 'WITHDRAW');
            $change = BranchChangeRequest::whereKey($id)->where('branch_id', $branchId)->lockForUpdate()->firstOrFail();
            if ($change->status !== 'PENDING' || $change->version !== $version) {
                throw new BookingConflictException('Yêu cầu đã thay đổi. Hãy tải lại trước khi xử lý.');
            }
            if ($action === 'APPROVE') {
                $this->assertEligible($branch);
                if (! BranchLocation::same(BranchLocation::snapshot($branch), $change->base_snapshot)) {
                    throw new BookingConflictException('Thông tin chi nhánh đã thay đổi kể từ khi gửi yêu cầu.');
                }
                $this->evidence($branch, $change->media_id);
                $next = $change->proposed_snapshot;
                $relocated = collect(['addressLine', 'districtId', 'ward'])->contains(fn ($key) => ($next[$key] ?? null) !== ($change->base_snapshot[$key] ?? null));
                if ($relocated && Booking::where('branch_id', $branchId)->where(function ($query) {
                    $query->whereIn('status', ['CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'])
                        // Existing pending holds are stored in the application timezone.
                        ->orWhere(fn ($pending) => $pending->where('status', 'PENDING')->where('pending_expires_at', '>', now()));
                })->exists()) {
                    throw new BookingConflictException('Cần giải quyết các lịch hẹn đang hiệu lực trước khi đổi địa chỉ.');
                }
                if ($relocated) {
                    // This is an observed pre-change location, not an invented historical address.
                    $snapshot = $change->base_snapshot + ['basis' => 'LEGACY_CURRENT_AT_CHANGE', 'capturedAt' => now('UTC')->toIso8601String()];
                    Booking::withTrashed()->where('branch_id', $branchId)->whereNull('branch_location_snapshot')->update(['branch_location_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                }
                $branch->forceFill(['address_line' => $next['addressLine'], 'registration_type' => $next['registrationType']])->save();
                DB::table('beautybook_branch_settings')->insertOrIgnore(['branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
                $settings = DB::table('beautybook_branch_settings')->where('branch_id', $branchId)->lockForUpdate()->first();
                $metadata = array_replace(json_decode($settings->metadata ?? '{}', true), $next);
                DB::table('beautybook_branch_settings')->where('branch_id', $branchId)->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                $this->activateEvidence($branch, $change->media_id);
            }
            $change->forceFill([
                'status' => match ($action) { 'APPROVE' => 'APPROVED', 'REJECT' => 'REJECTED', 'WITHDRAW' => 'WITHDRAWN' },
                'version' => $change->version + 1, 'review_note' => $note,
                'reviewed_by_user_id' => $action === 'WITHDRAW' ? null : $request->user()->id,
                'reviewed_at' => $action === 'WITHDRAW' ? null : now('UTC'),
            ])->save();
            $this->audit($request, $branch, $change, 'BRANCH_CHANGE_'.$change->status);

            return $change->refresh();
        }, 3);
    }

    private function activateEvidence(Branch $branch, int $mediaId): void
    {
        $document = DB::table('beautybook_documents')->where('business_id', $branch->business_id)
            ->where('branch_id', $branch->id)->where('status', 'ACTIVE')
            ->whereIn('document_type', ['BUSINESS_LICENSE', 'OPERATING_LICENSE', 'LOCATION_DOCUMENT'])
            ->orderBy('id')->lockForUpdate()->first();
        $documentId = $document?->id ?? DB::table('beautybook_documents')->insertGetId([
            'business_id' => $branch->business_id, 'branch_id' => $branch->id,
            'document_type' => 'BUSINESS_LICENSE', 'document_name' => 'Giấy đăng ký chi nhánh',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = (int) DB::table('beautybook_document_versions')->where('document_id', $documentId)->max('version') + 1;
        DB::table('beautybook_document_versions')->insert([
            'document_id' => $documentId, 'media_id' => $mediaId, 'version' => $version,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function audit(Request $request, Branch $branch, BranchChangeRequest $change, string $action): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id, 'business_id' => $branch->business_id,
            'action' => $action, 'entity_type' => 'BranchChangeRequest', 'entity_id' => $change->id,
            'metadata' => json_encode(['branchId' => $branch->id, 'version' => $change->version]), 'created_at' => now(),
        ]);
        $recipients = $action === 'BRANCH_CHANGE_SUBMITTED'
            ? \App\Models\RoleAssignment::where('role_name', 'PLATFORM_ADMIN')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
                ->whereHas('user', fn ($query) => $query->where('is_active', true))->pluck('user_id')
            : collect([$change->requested_by_user_id]);
        foreach ($recipients->unique() as $userId) {
            app(\App\Services\Booking\BookingNotificationService::class)->record(
                (int) $userId, $action, $action === 'BRANCH_CHANGE_SUBMITTED' ? 'Có yêu cầu thay đổi chi nhánh' : 'Yêu cầu thay đổi chi nhánh đã cập nhật',
                $branch->name, null, 'branch-change:'.$change->id.':'.$change->version.':'.$userId,
                metadata: ['branchId' => $branch->id, 'requestId' => $change->id, 'status' => $change->status],
            );
        }
    }
}
