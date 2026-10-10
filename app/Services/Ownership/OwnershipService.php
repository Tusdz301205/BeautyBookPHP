<?php

namespace App\Services\Ownership;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\Business;
use App\Models\BusinessOwnerProfile;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Booking\BookingNotificationService;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\SessionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class OwnershipService
{
    public const ACTIVE = ['PENDING_NEW_OWNER_ACCEPTANCE', 'UNDER_REVIEW', 'NEED_MORE_INFO', 'APPROVED', 'SCHEDULED'];

    public function owner(Request $request, Business $business): void
    {
        $assignment = app(SessionContext::class)->current($request);
        if ($assignment) $assignment = RoleAssignment::whereKey($assignment->id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
        abort_unless($assignment?->role_name === 'BUSINESS_OWNER' && (int)$assignment->business_id === $business->id
            && $business->ownerProfile?->user_id === $request->user()->id, 403);
    }

    public function admin(Request $request): void
    {
        $assignment = app(SessionContext::class)->current($request);
        abort_unless($assignment && RoleAssignment::whereKey($assignment->id)->where('user_id', $request->user()->id)->where('role_name', 'PLATFORM_ADMIN')->lockForUpdate()->first(), 403);
    }

    public function create(Request $request, Business $business, array $data): int
    {
        return DB::transaction(function () use ($request, $business, $data) {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $this->owner($request, $business);
            abort_unless(\Carbon\CarbonImmutable::parse($data['effective_at'])->isFuture(), 422, 'Hiệu lực chuyển chủ phải ở tương lai.');
            $recipient = User::where('email', $data['new_owner_email'])->lockForUpdate()->firstOrFail();
            abort_unless($recipient->account_kind === AccountKind::Operational && $recipient->is_active && $recipient->id !== $request->user()->id, 422, 'Chủ mới phải có tài khoản vận hành riêng đang hoạt động.');
            abort_if(DB::table('ownership_transfers')->where('business_id', $business->id)->whereIn('status', self::ACTIVE)->exists(), 409, 'Đã có yêu cầu chuyển chủ đang xử lý.');
            $id = DB::table('ownership_transfers')->insertGetId([
                'business_id' => $business->id, 'old_owner_user_id' => $request->user()->id, 'new_owner_user_id' => $recipient->id,
                'status' => 'PENDING_NEW_OWNER_ACCEPTANCE', 'effective_at' => $data['effective_at'], 'reason' => trim($data['reason']),
                'impact_snapshot' => json_encode([
                    'branch_ids' => $business->branches()->pluck('id'),
                    'future_bookings' => DB::table('bookings')->where('business_id', $business->id)->where('appointment_start_at', '>=', $data['effective_at'])->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'])->count(),
                    'pending_payments' => $this->pendingPayments($business->id)->count(),
                    'pending_refunds' => DB::table('refund_requests')->join('payment_transactions', 'payment_transactions.id', '=', 'refund_requests.payment_transaction_id')->join('bookings', 'bookings.id', '=', 'payment_transactions.booking_id')->where('bookings.business_id', $business->id)->whereIn('refund_requests.status', ['PENDING', 'APPROVED', 'PROCESSING'])->count(),
                ], JSON_THROW_ON_ERROR),
                'settlement_agreement' => isset($data['settlement_agreement']) ? json_encode($data['settlement_agreement'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request->user()->id, $business->id, $id, 'CREATE');
            app(BookingNotificationService::class)->record($recipient->id, 'OWNERSHIP_TRANSFER', 'Yêu cầu tiếp nhận doanh nghiệp', 'Xem yêu cầu tại mục Chuyển chủ.', null, 'ownership:'.$id.':incoming');
            DB::table('notifications')->where('dedupe_key', 'ownership:'.$id.':incoming')->update(['action_url' => '/ownership']);
            return $id;
        }, 3);
    }

    public function identity(Request $request, Business $business, array $data): int
    {
        return DB::transaction(function () use ($request, $business, $data) {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail(); $this->owner($request, $business);
            $type = $data['type'];
            $payload = $type === 'LEGAL_ENTITY' ? ['legal_name' => $data['legal_name'], 'tax_code' => $data['tax_code'] ?? null, 'registration_number' => $data['registration_number'] ?? null, 'representative_name' => $data['representative_name'] ?? null]
                : ['bank_name' => $data['bank_name'], 'account_holder' => $data['account_holder'], 'account_number' => $data['account_number']];
            $label = $type === 'LEGAL_ENTITY' ? $data['legal_name'] : $data['bank_name'].' · ****'.substr($data['account_number'], -4);
            $id = DB::table('business_identity_versions')->insertGetId([
                'business_id' => $business->id, 'type' => $type, 'version' => (int)DB::table('business_identity_versions')->where('business_id', $business->id)->where('type', $type)->max('version') + 1,
                'payload_ciphertext' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'display_label' => $label,
                'verification_status' => 'PENDING', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request->user()->id, $business->id, $id, 'IDENTITY_CREATE'); return $id;
        }, 3);
    }

    public function verify(Request $request, int $id, array $data): void
    {
        $this->admin($request);
        DB::transaction(function () use ($request, $id, $data) {
            $this->admin($request);
            $row = DB::table('business_identity_versions')->where('id', $id)->lockForUpdate()->first(); abort_unless($row, 404);
            abort_unless($row->verification_status === 'PENDING', 409);
            DB::table('business_identity_versions')->where('id', $id)->update(['verification_status' => $data['approve'] ? 'VERIFIED' : 'REJECTED', 'verified_by' => $request->user()->id, 'review_reason' => $data['reason'], 'updated_at' => now()]);
            $this->audit($request->user()->id, $row->business_id, $id, 'IDENTITY_VERIFY');
        }, 3);
    }

    public function action(Request $request, int $id, string $action, array $data): void
    {
        $initial = DB::table('ownership_transfers')->where('id', $id)->first(); abort_unless($initial, 404);
        DB::transaction(function () use ($request, $initial, $id, $action, $data) {
            $business = Business::whereKey($initial->business_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ownership_transfers')->where('id', $id)->lockForUpdate()->first();
            $update = ['updated_at' => now()];
            if ($action === 'accept') {
                abort_unless($row->new_owner_user_id === $request->user()->id && $request->user()->account_kind === AccountKind::Operational, 403);
                abort_unless($row->status === 'PENDING_NEW_OWNER_ACCEPTANCE', 409);
                $update += ['status' => 'UNDER_REVIEW', 'accepted_at' => now()];
            } elseif (in_array($action, ['cancel', 'resubmit'])) {
                $this->owner($request, $business); abort_unless($row->old_owner_user_id === $request->user()->id, 403);
                abort_unless(in_array($row->status, $action === 'cancel' ? ['PENDING_NEW_OWNER_ACCEPTANCE', 'UNDER_REVIEW', 'NEED_MORE_INFO', 'SCHEDULED'] : ['NEED_MORE_INFO']), 409);
                $update += ['status' => $action === 'cancel' ? 'CANCELLED' : 'UNDER_REVIEW', 'review_reason' => $data['reason']];
                if ($action === 'resubmit' && isset($data['settlement_agreement'])) $update['settlement_agreement'] = json_encode($data['settlement_agreement'], JSON_THROW_ON_ERROR);
            } elseif ($action === 'review') {
                $this->admin($request); abort_unless(in_array($row->status, ['UNDER_REVIEW', 'NEED_MORE_INFO']), 409);
                $decision = $data['decision'];
                if ($decision === 'APPROVE') {
                    abort_unless($row->status === 'UNDER_REVIEW' && $row->accepted_at, 409);
                    foreach (['LEGAL_ENTITY' => 'legal_version_id', 'PAYOUT_ACCOUNT' => 'payout_version_id'] as $type => $key) {
                        $version = DB::table('business_identity_versions')->where('business_id', $business->id)->where('type', $type)->where('verification_status', 'VERIFIED')->orderByDesc('version')->lockForUpdate()->first();
                        abort_unless($version, 409, 'Thiếu phiên bản pháp nhân hoặc tài khoản nhận tiền đã xác minh.'); $update[$key] = $version->id;
                    }
                    $update['status'] = \Carbon\CarbonImmutable::parse($row->effective_at, 'UTC')->lessThanOrEqualTo(now('UTC')) ? 'APPROVED' : 'SCHEDULED';
                    $update['approved_by'] = $request->user()->id;
                } else $update['status'] = $decision === 'NEED_MORE_INFO' ? 'NEED_MORE_INFO' : 'REJECTED';
                $update['review_reason'] = $data['reason'];
            } elseif ($action === 'execute') {
                $this->admin($request);
                if ($row->status === 'COMPLETED') return;
                abort_unless(in_array($row->status, ['APPROVED', 'SCHEDULED']) && $row->approved_by && $row->accepted_at && \Carbon\CarbonImmutable::parse($row->effective_at, 'UTC')->lessThanOrEqualTo(now('UTC')), 409);
                $oldUserId = $business->ownerProfile?->user_id;
                abort_unless($oldUserId === $row->old_owner_user_id, 409, 'Chủ hiện tại đã thay đổi.');
                // Financial mutations acquire this same Business lock before a Booking lock.
                abort_if($this->pendingPayments($business->id)->lockForUpdate()->exists(), 409, 'Còn giao dịch chờ xác minh.');
                abort_if(DB::table('refund_requests')->join('payment_transactions', 'payment_transactions.id', '=', 'refund_requests.payment_transaction_id')->join('bookings', 'bookings.id', '=', 'payment_transactions.booking_id')->where('bookings.business_id', $business->id)->whereIn('refund_requests.status', ['PENDING', 'APPROVED', 'PROCESSING'])->lockForUpdate()->exists(), 409, 'Còn yêu cầu hoàn tiền đang xử lý.');
                $users = User::whereIn('id', [$oldUserId, $row->new_owner_user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $newUser = $users[$row->new_owner_user_id]; abort_unless($newUser->account_kind === AccountKind::Operational && $newUser->is_active, 409);
                $newOwner = BusinessOwnerProfile::firstOrCreate(['user_id' => $newUser->id]);
                $business->update(['owner_profile_id' => $newOwner->id]);
                RoleAssignment::where('user_id', $oldUserId)->where('business_id', $business->id)->where('role_name', 'BUSINESS_OWNER')->delete();
                app(RoleAssignmentService::class)->grant($newUser, RoleName::BusinessOwner, $business, null, $request->user());
                DB::table('sessions')->whereIn('user_id', [$oldUserId, $newUser->id])->delete();
                User::whereIn('id', [$oldUserId, $newUser->id])->update(['remember_token' => null]);
                if (!DB::table('ownership_history')->where('business_id', $business->id)->exists()) DB::table('ownership_history')->insert(['business_id' => $business->id, 'owner_user_id' => $oldUserId, 'valid_from' => $business->created_at, 'valid_to' => $row->effective_at]);
                DB::table('ownership_history')->where('business_id', $business->id)->whereNull('valid_to')->update(['valid_to' => $row->effective_at]);
                DB::table('ownership_history')->insert(['business_id' => $business->id, 'owner_user_id' => $newUser->id, 'transfer_id' => $id, 'valid_from' => $row->effective_at]);
                foreach (['LEGAL_ENTITY' => $row->legal_version_id, 'PAYOUT_ACCOUNT' => $row->payout_version_id] as $type => $versionId) {
                    $version = DB::table('business_identity_versions')->where('id', $versionId)->where('business_id', $business->id)->where('type', $type)->where('verification_status', 'VERIFIED')->lockForUpdate()->first(); abort_unless($version, 409);
                    DB::table('business_identity_versions')->where('business_id', $business->id)->where('type', $type)->where('is_active', true)->update(['is_active' => false, 'valid_to' => $row->effective_at]);
                    DB::table('business_identity_versions')->where('id', $versionId)->update(['is_active' => true, 'valid_from' => $row->effective_at, 'valid_to' => null]);
                }
                $update += ['status' => 'COMPLETED', 'completed_at' => now()];
            } else abort(404);
            DB::table('ownership_transfers')->where('id', $id)->update($update);
            $this->audit($request->user()->id, $business->id, $id, strtoupper($action));
            foreach ([$row->old_owner_user_id, $row->new_owner_user_id] as $recipient) app(BookingNotificationService::class)->record($recipient, 'OWNERSHIP_TRANSFER', 'Yêu cầu chuyển chủ được cập nhật', $update['status'], null, 'ownership:'.$id.':'.$update['status'].':'.$recipient);
        }, 3);
    }

    private function audit(int $actor, int $business, int $id, string $action): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actor, 'business_id' => $business, 'action' => $action, 'entity_type' => 'OwnershipTransfer', 'entity_id' => (string)$id, 'created_at' => now()]);
    }

    private function pendingPayments(int $businessId): \Illuminate\Database\Query\Builder
    {
        return DB::table('payment_transactions')
            ->leftJoin('bookings', 'bookings.id', '=', 'payment_transactions.booking_id')
            ->leftJoin('package_purchases', 'package_purchases.id', '=', 'payment_transactions.package_purchase_id')
            ->where('payment_transactions.status', 'PENDING')
            ->where(fn ($query) => $query->where('bookings.business_id', $businessId)->orWhere('package_purchases.business_id', $businessId));
    }

    public function executeDue(): int
    {
        $rows = DB::table('ownership_transfers')->whereIn('status', ['APPROVED', 'SCHEDULED'])
            ->where('effective_at', '<=', now('UTC'))->orderBy('effective_at')->limit(20)->get();
        $count = 0;
        foreach ($rows as $row) {
            $actor = User::find($row->approved_by);
            $assignment = $actor?->roleAssignments()->where('role_name', 'PLATFORM_ADMIN')->first();
            if (!$actor || !$actor->is_active || !$assignment) continue;
            $request = Request::create('/maintenance/ownership');
            $request->setUserResolver(fn () => $actor);
            $session = new \Illuminate\Session\Store('ownership-job', new \Illuminate\Session\ArraySessionHandler(120));
            $session->put('workspace_assignment_id', $assignment->id);
            $request->setLaravelSession($session);
            try {
                $this->action($request, $row->id, 'execute', []); $count++;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                // Keep due requests retryable; obligations can clear after manual verification/refund.
                DB::table('ownership_transfers')->where('id', $row->id)->whereIn('status', ['APPROVED', 'SCHEDULED'])
                    ->update(['review_reason' => 'Chưa thể thực thi: kiểm tra nghĩa vụ và quyền xét duyệt.', 'updated_at' => now()]);
            }
        }
        return $count;
    }
}
