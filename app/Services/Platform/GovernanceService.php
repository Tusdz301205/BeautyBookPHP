<?php

namespace App\Services\Platform;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\StaffBranchAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Booking\BookingNotificationService;
use App\Services\Branches\BranchLifecycleService;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\SessionContext;
use App\Services\Operations\ImpactService;
use App\Services\Promotions\PromotionPricingService as Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GovernanceService
{
    public function scope(Request $request, array $roles = ['PLATFORM_ADMIN']): RoleAssignment
    {
        $assignment = app(SessionContext::class)->current($request);
        abort_unless($assignment && $request->user()->account_kind === AccountKind::Operational && in_array($assignment->role_name, $roles, true), 403);

        return $assignment;
    }

    private function lockedScope(Request $request, array $roles = ['PLATFORM_ADMIN']): RoleAssignment
    {
        // A shared role row serializes last-admin checks across different target users.
        Role::whereKey('PLATFORM_ADMIN')->lockForUpdate()->firstOrFail();
        $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless((bool) $actor->is_active, 403);
        $assignment = RoleAssignment::whereKey($request->session()->get('workspace_assignment_id'))->where('user_id', $actor->id)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now('UTC')))->lockForUpdate()->first();
        abort_unless($assignment && in_array($assignment->role_name, $roles, true), 403);

        return $assignment;
    }

    public function suspensionImpact(User $user): array
    {
        $activeBookings = fn ($query) => $query
            ->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'])
            ->where(fn ($q) => $q->where('appointment_start_at', '>=', now('Asia/Ho_Chi_Minh')->startOfDay()->utc())
                ->orWhereIn('status', ['CHECKED_IN', 'IN_PROGRESS']));
        $customerBookings = Booking::whereHas('customerProfile', fn ($q) => $q->where('user_id', $user->id))
            ->where($activeBookings)->count();
        $assignedServices = BookingService::whereHas('staffProfile', fn ($q) => $q->where('user_id', $user->id))
            ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
            ->whereHas('booking', $activeBookings)->count();
        $activeAssignments = StaffBranchAssignment::whereHas('staff', fn ($q) => $q->where('user_id', $user->id))
            ->where('status', 'ACTIVE')->count();
        $ownedBusinesses = Business::whereHas('ownerProfile', fn ($q) => $q->where('user_id', $user->id))->count();

        return [
            'id' => (string) $user->id, 'isActive' => (bool) $user->is_active,
            'customerBookings' => $customerBookings, 'assignedServices' => $assignedServices,
            'activeAssignments' => $activeAssignments, 'ownedBusinesses' => $ownedBusinesses,
            'requiresAcknowledgement' => $customerBookings + $assignedServices + $activeAssignments + $ownedBusinesses > 0,
            'handling' => 'KEEP_EXISTING_AND_COORDINATE',
        ];
    }

    public function status(Request $request, User $user, bool $active, string $reason, ?bool $expectedIsActive = null, bool $acknowledgeImpact = false): User
    {
        if (trim($reason) === '') {
            throw new DomainException('Lý do thay đổi là bắt buộc.');
        }

        return DB::transaction(function () use ($request, $user, $active, $reason, $expectedIsActive, $acknowledgeImpact) {
            $this->lockedScope($request);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = (bool) $user->is_active;
            if (! $active && (int) $user->id === (int) $request->user()->id) {
                abort(403, 'Không thể tự khóa tài khoản quản trị đang dùng.');
            }
            // The BeautyBook API supplies an explicit target and observed state so retries cannot toggle an account.
            if ($expectedIsActive !== null) {
                if ($before === $active) return $user;
                abort_if($before !== $expectedIsActive, 409, 'Trạng thái đã thay đổi. Hãy tải lại hồ sơ.');
                if (! $active && ! $acknowledgeImpact && $this->suspensionImpact($user)['requiresAcknowledgement']) {
                    abort(409, 'Cần xác nhận ảnh hưởng đến lịch hẹn và phân công trước khi khóa.');
                }
            }
            if (! $active && $user->is_active && $user->roleAssignments()->where('role_name', 'PLATFORM_ADMIN')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now('UTC')))->exists() && $this->activeAdminCount() <= 1) {
                throw new DomainException('Phải giữ ít nhất một quản trị viên nền tảng hoạt động.');
            }
            $user->forceFill(['is_active' => $active])->save();
            if (! $active) {
                $this->revokeSessions($user);
            }
            $this->audit($request->user()->id, null, 'STATUS_CHANGE', 'User', $user->id, ['before' => $before, 'after' => $active, 'reason' => $reason]);

            return $user;
        }, 3);
    }

    public function grantRole(Request $request, User $user, array $data): RoleAssignment
    {
        return DB::transaction(function () use ($request, $user, $data) {
            $actor = $this->lockedScope($request, ['PLATFORM_ADMIN', 'BUSINESS_OWNER']);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $role = RoleName::from($data['role_name']);
            $business = isset($data['business_id']) ? Business::findOrFail($data['business_id']) : null;
            $branch = isset($data['branch_id']) ? Branch::findOrFail($data['branch_id']) : null;
            if ($actor->role_name === 'BUSINESS_OWNER') {
                abort_unless(in_array($role, [RoleName::Staff, RoleName::Receptionist], true) && $business && (int) $business->id === (int) $actor->business_id, 403);
            }
            $key = app(RoleAssignmentService::class)->scopeKey($role, $business, $branch);
            if ($user->roleAssignments()->where('role_name', $role->value)->where('scope_key', $key)->exists()) {
                abort(409, 'Vai trò đã được cấp trong phạm vi này.');
            }
            $assignment = app(RoleAssignmentService::class)->grant($user, $role, $business, $branch, $request->user(), $data['expires_at'] ?? null);
            $this->revokeSessions($user);
            $this->audit($request->user()->id, $business?->id, 'GRANT_ROLE', 'User', $user->id, ['role' => $role->value, 'scope_key' => $key]);

            return $assignment;
        }, 3);
    }

    public function revokeRole(Request $request, User $user, RoleAssignment $assignment): void
    {
        DB::transaction(function () use ($request, $user, $assignment) {
            $actor = $this->lockedScope($request, ['PLATFORM_ADMIN', 'BUSINESS_OWNER']);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $assignment = RoleAssignment::whereKey($assignment->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($actor->role_name === 'BUSINESS_OWNER') {
                abort_unless(in_array($assignment->role_name, ['STAFF', 'RECEPTIONIST'], true) && (int) $assignment->business_id === (int) $actor->business_id, 403);
            }
            if ($assignment->role_name === 'CUSTOMER') {
                throw new DomainException('Vai trò CUSTOMER gắn với loại tài khoản và không được gỡ bằng quản trị vận hành.');
            }
            if ($assignment->role_name === 'PLATFORM_ADMIN' && $user->is_active && (! $assignment->expires_at || $assignment->expires_at->isFuture()) && $this->activeAdminCount() <= 1) {
                throw new DomainException('Phải giữ ít nhất một quản trị viên nền tảng hoạt động.');
            }
            $this->audit($request->user()->id, $assignment->business_id, 'REVOKE_ROLE', 'User', $user->id, ['role' => $assignment->role_name, 'scope_key' => $assignment->scope_key]);
            app(RoleAssignmentService::class)->revoke($assignment);
            $this->revokeSessions($user);
        }, 3);
    }

    private function activeAdminCount(): int
    {
        return User::where('is_active', true)->whereHas('roleAssignments', fn ($q) => $q->where('role_name', 'PLATFORM_ADMIN')->where(fn ($expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now('UTC'))))->count();
    }

    private function revokeSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => null])->save();
    }

    private function audit(int $actorId, ?int $businessId, string $action, string $entity, int $id, array $metadata): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actorId, 'business_id' => $businessId, 'action' => $action, 'entity_type' => $entity, 'entity_id' => (string) $id, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function trustMetrics(Business $business): array
    {
        $bookings = Booking::where('business_id', $business->id)->where('created_at', '>=', now()->subDays(90))->with('statusHistory')->get();
        $total = $bookings->count();
        $salonCancelled = 0;
        $late = 0;
        $confirmTimes = [];
        foreach ($bookings as $booking) {
            $confirmation = $booking->statusHistory->firstWhere('to_status', 'CONFIRMED');
            if ($confirmation) {
                $minutes = $booking->created_at->diffInMinutes($confirmation->created_at, false);
                if ($minutes > 0) {
                    $confirmTimes[] = $minutes;
                }
            }
            $cancel = $booking->statusHistory->last(fn ($row) => $row->to_status === 'CANCELLED');
            if ($booking->status === 'CANCELLED' && $cancel && in_array($cancel->actor_kind, ['OWNER', 'RECEPTIONIST'], true)) {
                $salonCancelled++;
                $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
                $diff = $cancel->created_at->diffInMinutes($start, false);
                if ($diff >= 0 && $diff < 120) {
                    $late++;
                }
            }
        }
        $rate = $total ? $salonCancelled / $total : 0;
        $lateRate = $salonCancelled ? $late / $salonCancelled : 0;
        $confirmationMinutes = $confirmTimes ? array_sum($confirmTimes) / count($confirmTimes) : 0;
        $score = max(0, 100 - (int) round($rate * 50) - (int) round($lateRate * 100) - ($confirmationMinutes > 1440 ? 15 : 0) - ($confirmationMinutes > 2880 ? 15 : 0));
        $reasons = [];
        if ($rate >= .1) {
            $reasons[] = 'Tỷ lệ cơ sở hủy lịch '.round($rate * 100, 1).'%';
        }
        if ($lateRate >= .2) {
            $reasons[] = 'Tỷ lệ cơ sở hủy sát giờ '.round($lateRate * 100, 1).'%';
        }
        if ($confirmationMinutes > 1440) {
            $reasons[] = 'Thời gian xác nhận trung bình '.round($confirmationMinutes).' phút';
        }

        return ['total_bookings' => $total, 'cancellation_rate' => $rate, 'no_show_rate' => $total ? $bookings->where('status', 'NO_SHOW')->count() / $total : 0, 'late_cancel_by_salon_rate' => $lateRate, 'avg_confirmation_time_minutes' => $confirmationMinutes, 'trust_score' => $score, 'alert_level' => $score >= 80 ? 'OK' : ($score >= 60 ? 'WARN' : 'DANGER'), 'reasons' => $reasons];
    }

    public function rebuildTrust(int $actorId): int
    {
        $count = 0;
        foreach (Business::whereIn('status', ['APPROVED', 'ACTIVE', 'SUSPENDED'])->get() as $business) {
            $metrics = $this->trustMetrics($business);
            $metrics['reasons'] = json_encode($metrics['reasons'], JSON_THROW_ON_ERROR);
            DB::table('trust_snapshots')->updateOrInsert(['business_id' => $business->id], $metrics + ['computed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->proposeThresholdReview($business, $actorId);
            $count++;
        }
        $this->audit($actorId, null, 'REBUILD_TRUST', 'TrustSnapshot', 0, ['businesses' => $count, 'period_days' => 90]);

        return $count;
    }

    private function proposeThresholdReview(Business $business, int $actorId): void
    {
        $threshold = (int) app(PlatformSettingsService::class)->value('violationSuspendThreshold');
        DB::transaction(function () use ($business, $actorId, $threshold) {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            if ($business->status->value === 'SUSPENDED' || $business->booking_restricted_at) {
                return;
            }
            $signals = DB::table('trust_actions')->where('business_id', $business->id)->where('outcome', 'APPLIED')
                ->whereIn('action', ['WARNING_SENT', 'EXPLANATION_REQUESTED', 'BOOKING_RESTRICTED', 'SUSPENDED'])
                ->where('created_at', '>=', now()->subDays(90))->count();
            if ($signals < $threshold || DB::table('trust_actions')->where('business_id', $business->id)
                ->where('internal_note', 'THRESHOLD_REVIEW')->whereIn('outcome', ['PENDING_APPROVAL', 'PENDING_IMPACT'])->exists()) {
                return;
            }
            $id = DB::table('trust_actions')->insertGetId(['business_id' => $business->id, 'actor_user_id' => $actorId,
                'action' => 'SUSPENDED', 'reason' => "Đạt {$signals} tín hiệu vi phạm trong 90 ngày (ngưỡng {$threshold}); đề nghị quản trị viên xem xét.",
                'internal_note' => 'THRESHOLD_REVIEW', 'status_before' => $business->status->value, 'status_after' => $business->status->value,
                'outcome' => 'PENDING_APPROVAL', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actorId, $business->id, 'TRUST_PROPOSED', 'Business', $business->id, ['action_id' => $id, 'signals' => $signals, 'threshold' => $threshold]);
        }, 3);
    }

    public function trustAction(Request $request, Business $business, array $data): object
    {
        $this->scope($request);
        $branch = isset($data['branch_id']) ? Branch::whereKey($data['branch_id'])->where('business_id', $business->id)->firstOrFail() : null;
        $action = $data['action'];
        $reason = trim($data['reason']);
        if ($reason === '') {
            throw new DomainException('Lý do xử lý là bắt buộc.');
        }
        if (! $branch) {
            return $this->businessTrustPreflight($request, $business, $data);
        }
        $before = $branch?->status->value ?? $business->status->value;
        $restore = null;
        if ($action === 'RESTORED') {
            $restore = DB::table('trust_actions')->where('business_id', $business->id)->where('branch_id', $branch?->id)->whereIn('action', ['BOOKING_RESTRICTED', 'SUSPENDED'])->where('outcome', 'APPLIED')->whereNull('restored_at')->latest('id')->first();
            if (! $restore) {
                throw new DomainException('Không có quyết định hạn chế hoặc tạm ngưng chưa được khôi phục.');
            }
        }
        $operational = in_array($action, ['BOOKING_RESTRICTED', 'SUSPENDED', 'RESTORED'], true);
        if ($operational && ! $branch) {
            throw new DomainException('Quyết định ảnh hưởng vận hành cần chọn chi nhánh để xử lý lịch bị ảnh hưởng trước.');
        }
        $intentId = DB::transaction(function () use ($request, $business, $branch, $data, $reason, $before, $restore) {
            $this->lockedScope($request);

            return DB::table('trust_actions')->insertGetId(['business_id' => $business->id, 'branch_id' => $branch?->id, 'actor_user_id' => $request->user()->id, 'action' => $data['action'], 'reason' => $reason, 'internal_note' => $data['internal_note'] ?? null, 'status_before' => $before, 'status_after' => $before, 'outcome' => 'PENDING', 'restore_of_action_id' => $restore?->id, 'created_at' => now(), 'updated_at' => now()]);
        }, 3);
        $impact = null;
        if ($branch && in_array($action, ['BOOKING_RESTRICTED', 'SUSPENDED'], true) && Booking::where('branch_id', $branch->id)->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'])->where('appointment_end_at', '>', now('UTC'))->exists()) {
            $impact = app(ImpactService::class)->openForBranch($branch, $request->user()->id, $action === 'BOOKING_RESTRICTED' ? 'PAUSE' : 'SUSPEND', $reason);
        }
        try {
            $decision = DB::transaction(function () use ($request, $business, $branch, $data, $reason, $before, $restore, $intentId, $operational, $action, &$impact) {
                if ($branch) {
                    $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
                }
                Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
                $this->lockedScope($request);
                if ($branch && in_array($action, ['BOOKING_RESTRICTED', 'SUSPENDED'], true)) {
                    $impactService = app(ImpactService::class);
                    if (! $impactService->refreshForClosure($branch)) {
                        $impact = $impactService->openForBranch($branch, $request->user()->id, $action === 'BOOKING_RESTRICTED' ? 'PAUSE' : 'SUSPEND', $reason);

                        return null;
                    }
                }
                if ($restore && ! DB::table('trust_actions')->where('id', $restore->id)->whereNull('restored_at')->lockForUpdate()->first()) {
                    throw new DomainException('Quyết định đã được khôi phục bởi thao tác khác.');
                }
                $after = $before;
                if ($operational) {
                    $lifecycle = app(BranchLifecycleService::class);
                    $result = match ($action) {
                        'SUSPENDED' => $lifecycle->suspend($branch, $request->user(), $reason),
                        'BOOKING_RESTRICTED' => $lifecycle->pause($branch, $request->user(), $reason),
                        'RESTORED' => $lifecycle->restore($branch, $request->user(), $reason),
                    };
                    $after = $result->operational_status->value;
                }
                DB::table('trust_actions')->where('id', $intentId)->update(['status_after' => $after, 'outcome' => 'APPLIED', 'updated_at' => now()]);
                if ($restore) {
                    DB::table('trust_actions')->where('id', $restore->id)->update(['restored_at' => now(), 'updated_at' => now()]);
                }
                $this->audit($request->user()->id, $business->id, 'TRUST_ACTION', 'Business', $business->id, ['action_id' => $intentId, 'action' => $data['action'], 'before' => $before, 'after' => $after, 'reason' => $reason]);
                if (in_array($data['action'], ['WARNING_SENT', 'EXPLANATION_REQUESTED', 'BOOKING_RESTRICTED', 'SUSPENDED', 'RESTORED'], true)) {
                    foreach (RoleAssignment::where('business_id', $business->id)->where('role_name', 'BUSINESS_OWNER')->pluck('user_id')->unique() as $ownerId) {
                        app(BookingNotificationService::class)->record($ownerId, 'SALON_VIOLATION_ALERT', 'Thông báo Trust & Safety', $reason, null, 'TRUST:'.$intentId.':'.$ownerId);
                    }
                }

                return DB::table('trust_actions')->find($intentId);
            }, 3);
            if (! $decision) {
                throw new DomainException('Cần hoàn tất xử lý mọi lịch bị ảnh hưởng trước khi hạn chế vận hành chi nhánh.');
            }

            return $decision;
        } catch (DomainException $e) {
            DB::table('trust_actions')->where('id', $intentId)->update(['outcome' => $impact ? 'PENDING_IMPACT' : 'BLOCKED', 'updated_at' => now()]);
            $result = DB::table('trust_actions')->find($intentId);
            $result->impact_case_id = $impact?->id;
            $result->message = $e->getMessage();

            return $result;
        }
    }

    public function dashboard(RoleAssignment $scope, array $filters): array
    {
        $bookings = Booking::query();
        if ($scope->role_name === 'BUSINESS_OWNER') {
            $bookings->where('bookings.business_id', $scope->business_id);
        }
        if (isset($filters['branch_id'])) {
            $branch = Branch::findOrFail($filters['branch_id']);
            abort_unless($scope->role_name === 'PLATFORM_ADMIN' || (int) $branch->business_id === (int) $scope->business_id, 403);
            $bookings->where('bookings.branch_id', $branch->id);
        }
        $from = CarbonImmutable::parse($filters['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? now()->toDateString())->addDay()->startOfDay();
        $inPeriod = (clone $bookings)->where('bookings.created_at', '>=', $from)->where('bookings.created_at', '<', $to);
        $ledger = DB::table('payment_ledger_entries')->whereIn('booking_id', (clone $bookings)->select('bookings.id'))->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $gross = (int) (clone $ledger)->where('type', 'PAYMENT_RECEIVED')->sum('amount_cents');
        $refunds = (int) (clone $ledger)->whereIn('type', ['REVERSAL', 'REFUND'])->sum('amount_cents');
        $branchStats = (clone $inPeriod)->join('branches', 'branches.id', '=', 'bookings.branch_id')->selectRaw('branches.id, branches.name, COUNT(*) as booked_count, SUM(bookings.status = "COMPLETED") as completed_count')->groupBy('branches.id', 'branches.name')->orderByDesc('booked_count')->limit(10)->get();
        $serviceStats = DB::table('booking_services')->join('bookings', 'bookings.id', '=', 'booking_services.booking_id')->whereIn('bookings.id', (clone $inPeriod)->select('bookings.id'))->selectRaw('service_name_snapshot as name, COUNT(*) as booked_count, SUM(bookings.status = "COMPLETED") as completed_count')->groupBy('service_name_snapshot')->orderByDesc('booked_count')->limit(10)->get();
        $monthly = (clone $ledger)->whereIn('type', ['PAYMENT_RECEIVED', 'REVERSAL', 'REFUND'])->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(CASE WHEN type = "PAYMENT_RECEIVED" THEN amount_cents ELSE -CAST(amount_cents AS SIGNED) END) as net_cents')->groupBy('month')->orderBy('month')->get();

        return ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(), 'gross_revenue' => Money::money($gross), 'refund_amount' => Money::money($refunds), 'net_revenue' => $gross >= $refunds ? Money::money($gross - $refunds) : '-'.Money::money($refunds - $gross), 'payment_count' => (clone $ledger)->where('type', 'PAYMENT_RECEIVED')->count(), 'bookings' => (clone $inPeriod)->count(), 'statuses' => (clone $inPeriod)->selectRaw('status, COUNT(*) as total')->groupBy('status')->get(), 'branches' => $branchStats, 'services' => $serviceStats, 'monthly' => $monthly];
    }

    private function businessTrustPreflight(Request $request, Business $business, array $data): object
    {
        $intent = DB::transaction(function () use ($request, $business, $data) {
            $branches = Branch::where('business_id', $business->id)->orderBy('id')->lockForUpdate()->get();
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $this->lockedScope($request);
            $restore = $data['action'] === 'RESTORED' ? DB::table('trust_actions')->where('business_id', $business->id)->whereNull('branch_id')->where('outcome', 'APPLIED')->whereNull('restored_at')->where(function ($query) {
                $query->whereIn('action', ['BOOKING_RESTRICTED', 'SUSPENDED'])->orWhere('status_after', 'SUSPENDED');
            })->latest('id')->lockForUpdate()->first() : null;
            if ($data['action'] === 'RESTORED' && ! $restore) {
                throw new DomainException('Không có quyết định hạn chế hoặc tạm ngưng chưa được khôi phục.');
            }
            $adverse = ['WARNING_SENT', 'EXPLANATION_REQUESTED', 'BOOKING_RESTRICTED', 'SUSPENDED'];
            $threshold = (int) app(PlatformSettingsService::class)->value('violationSuspendThreshold');
            $signals = DB::table('trust_actions')->where('business_id', $business->id)->where('outcome', 'APPLIED')->whereIn('action', $adverse)->where('created_at', '>=', now()->subDays(90))->count();
            $autoSuspend = in_array($data['action'], $adverse, true) && $signals + 1 >= $threshold;
            $effective = $autoSuspend ? 'SUSPENDED' : $data['action'];
            $id = DB::table('trust_actions')->insertGetId(['business_id' => $business->id, 'branch_id' => null, 'actor_user_id' => $request->user()->id, 'action' => $data['action'], 'reason' => trim($data['reason']), 'internal_note' => $data['internal_note'] ?? null, 'status_before' => $business->status->value, 'status_after' => $business->status->value, 'outcome' => 'PENDING', 'restore_of_action_id' => $restore?->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request->user()->id, $business->id, 'TRUST_PROPOSED', 'Business', $business->id, ['action_id' => $id, 'action' => $data['action'], 'branch_count' => $branches->count(), 'reason' => trim($data['reason'])]);

            return ['id' => $id, 'branches' => $branches, 'effective' => $effective, 'restore' => $restore, 'threshold' => $threshold, 'automatic' => $autoSuspend];
        }, 3);
        $cases = [];
        if (in_array($intent['effective'], ['BOOKING_RESTRICTED', 'SUSPENDED'], true)) {
            foreach ($intent['branches'] as $branch) {
                if (Booking::where('branch_id', $branch->id)->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'])->where('appointment_end_at', '>', now('UTC'))->exists()) {
                    $cases[] = app(ImpactService::class)->openForBranch($branch, $request->user()->id, $intent['effective'] === 'BOOKING_RESTRICTED' ? 'PAUSE' : 'SUSPEND', $data['reason'])->id;
                }
            }
        }
        $applied = DB::transaction(function () use ($request, $business, $data, $intent, &$cases) {
            $branches = Branch::where('business_id', $business->id)->orderBy('id')->lockForUpdate()->get();
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $this->lockedScope($request);
            $ready = true;
            if (in_array($intent['effective'], ['BOOKING_RESTRICTED', 'SUSPENDED'], true)) {
                foreach ($branches as $branch) {
                    if (! app(ImpactService::class)->refreshForClosure($branch)) {
                        $cases[] = app(ImpactService::class)->openForBranch($branch, $request->user()->id, $intent['effective'] === 'BOOKING_RESTRICTED' ? 'PAUSE' : 'SUSPEND', $data['reason'])->id;
                        $ready = false;
                    }
                }
            }
            if (! $ready) {
                return false;
            }
            $after = $business->status->value;
            if (in_array($intent['effective'], ['BOOKING_RESTRICTED', 'SUSPENDED'], true)) {
                $after = $intent['effective'];
                $business->forceFill(['booking_restricted_at' => now(), 'booking_restriction_reason' => $intent['automatic'] ? 'Tự động tạm ngưng khi đạt '.$intent['threshold'].' tín hiệu vi phạm. '.trim($data['reason']) : trim($data['reason']), ...($after === 'SUSPENDED' ? ['status' => 'SUSPENDED'] : [])])->save();
            } elseif ($intent['effective'] === 'RESTORED') {
                $restore = DB::table('trust_actions')->where('id', $intent['restore']->id)->whereNull('restored_at')->lockForUpdate()->first();
                if (! $restore) {
                    throw new DomainException('Quyết định đã được khôi phục bởi thao tác khác.');
                }
                $after = $restore->status_after === 'SUSPENDED' && $restore->status_before !== 'SUSPENDED' ? $restore->status_before : $business->status->value;
                $business->forceFill(['status' => $after, 'booking_restricted_at' => null, 'booking_restriction_reason' => null])->save();
                DB::table('trust_actions')->where('id', $restore->id)->update(['restored_at' => now(), 'updated_at' => now()]);
            }
            DB::table('trust_actions')->where('id', $intent['id'])->update(['status_after' => $after, 'outcome' => 'APPLIED', 'updated_at' => now()]);
            $this->audit($request->user()->id, $business->id, 'TRUST_ACTION', 'Business', $business->id, ['action_id' => $intent['id'], 'action' => $data['action'], 'after' => $after, 'automatic' => $intent['automatic']]);
            foreach (RoleAssignment::where('business_id', $business->id)->where('role_name', 'BUSINESS_OWNER')->pluck('user_id')->unique() as $ownerId) {
                app(BookingNotificationService::class)->record($ownerId, 'SALON_VIOLATION_ALERT', 'Thông báo Trust & Safety', trim($data['reason']), null, 'TRUST:'.$intent['id'].':'.$ownerId);
            }

            return true;
        }, 3);
        if (! $applied) {
            DB::table('trust_actions')->where('id', $intent['id'])->update(['outcome' => 'PENDING_IMPACT', 'updated_at' => now()]);
        }
        $result = DB::table('trust_actions')->find($intent['id']);
        $result->impact_case_ids = array_values(array_unique($cases));
        $result->message = $applied ? 'Đã áp dụng quyết định quản trị doanh nghiệp.' : 'Cần hoàn tất xử lý lịch bị ảnh hưởng ở mọi chi nhánh; doanh nghiệp chưa đổi trạng thái.';

        return $result;
    }
}
