<?php

namespace App\Services\Payout;

use App\Services\Beautybook\ApiScope;
use App\Services\Booking\BookingNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Business payout request -> admin approval -> mock provider execution (business-withdrawal.service.ts).
 * Balances are derived, never stored: payables from verified SePay payments of completed bookings, less
 * refunds/reversals, less unreleased withdrawal allocations. Locks run business -> withdrawal.
 */
class WithdrawalService
{
    public const STATUSES = ['PENDING_APPROVAL', 'PROCESSING', 'FAILED', 'SIMULATED_COMPLETED', 'REJECTED', 'CANCELLED'];

    public function __construct(private ApiScope $scope) {}

    /**
     * One row per payable of the scope: `remaining` = the released deposit, less refunds recorded against it after
     * release (a refund hold or a paid refund), less unreleased allocations (open or paid withdrawals). It is NOT
     * clamped at zero: a negative value means money was refunded after it had been allocated, and that shortfall
     * must not be paid out. Callers must hold the business lock, and take it before their first non-locking read in
     * the transaction, so the snapshot already contains every change committed before the lock was granted.
     */
    private function payableRows(int $businessId, string $scope, bool $lock = false)
    {
        $sql = 'SELECT p.id, p.amount_vnd
                   - LEAST(p.amount_vnd, COALESCE((SELECT SUM(r.amount_vnd) FROM deposit_refunds r WHERE r.deposit_id = p.deposit_id AND r.status IN (\'PENDING\',\'CONFIRMED\')), 0))
                   - COALESCE((SELECT SUM(a.amount_vnd) FROM withdrawal_allocations a WHERE a.payable_id = p.id AND a.released = 0), 0) AS remaining
                FROM business_payables p
                JOIN booking_deposits d ON d.id = p.deposit_id
                JOIN wallets w ON w.id = d.wallet_id
                WHERE p.business_id = ? AND p.settlement_id IS NULL AND w.scope = ?
                ORDER BY p.created_at, p.id'.($lock ? ' FOR UPDATE OF p' : '');

        return collect(DB::select($sql, [$businessId, $scope]));
    }

    /**
     * True while every payable this withdrawal draws on can still cover all allocations against it. A refund or
     * reversal raised after the request makes it false, and the money must then not leave the platform.
     * Runs under the business lock (taken before the transaction's first non-locking read).
     */
    private function fundsIntact(int $businessId, object $withdrawal): bool
    {
        $allocations = DB::table('withdrawal_allocations')->where('withdrawal_id', $withdrawal->id)->where('released', 0)->get(['payable_id', 'amount_vnd']);
        if ($allocations->isEmpty() || (int) $allocations->sum('amount_vnd') !== (int) $withdrawal->amount_vnd) return false;
        $rows = $this->payableRows($businessId, (string) $withdrawal->wallet_scope, true)->keyBy('id');
        foreach ($allocations as $allocation) {
            $row = $rows->get($allocation->payable_id);
            if (! $row || (int) $row->remaining < 0) return false;
        }

        return true;
    }

    public function balances(int $businessId, string $scope = 'LIVE_LAB'): array
    {
        // Shortfalls (refunds after a payout) are netted against other payables, never below zero overall.
        $available = max(0, (int) $this->payableRows($businessId, $scope)->sum('remaining'));
        $sum = fn (array $statuses) => (int) DB::table('business_withdrawals')->where('business_id', $businessId)->where('wallet_scope', $scope)->whereIn('status', $statuses)->sum('amount_vnd');
        $settled = fn (string $status) => (int) DB::table('sim_settlements')->where('business_id', $businessId)->where('wallet_scope', $scope)->where('status', $status)->sum('amount_vnd');

        return ['available' => $available, 'locked' => $sum(['PENDING_APPROVAL', 'PROCESSING', 'FAILED']) + $settled('PENDING'), 'completed' => $sum(['SIMULATED_COMPLETED']) + $settled('SIMULATED_PAID')];
    }

    public function present(object $w): array
    {
        return ['id' => (string) $w->id, 'businessId' => (string) $w->business_id, 'amountVnd' => (string) $w->amount_vnd, 'status' => $w->status, 'walletScope' => $w->wallet_scope, 'version' => (int) $w->version,
            'reason' => $w->reason, 'bankSnapshot' => json_decode($w->bank_snapshot, true), 'provider' => 'MOCK', 'simulation' => true,
            'requestedAt' => \Carbon\CarbonImmutable::parse($w->created_at, config('app.timezone'))->utc()->toISOString(),
            'completedAt' => $w->completed_at ? \Carbon\CarbonImmutable::parse($w->completed_at, 'UTC')->toISOString() : null,
            'attempts' => DB::table('withdrawal_attempts')->where('withdrawal_id', $w->id)->orderBy('sequence')->get(['sequence', 'provider', 'status', 'reference', 'error_code'])->map(fn ($a) => (array) $a)->all(),
            'events' => DB::table('withdrawal_events')->where('withdrawal_id', $w->id)->orderBy('id')->get(['from_status', 'to_status', 'reason', 'created_at'])->map(fn ($e) => (array) $e)->all()];
    }

    private function event(object $w, ?string $from, string $to, string $reason, ?int $actorId): void
    {
        DB::table('withdrawal_events')->insert(['withdrawal_id' => $w->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $actorId, 'created_at' => now()]);
        DB::table('audit_logs')->insert(['user_id' => $actorId, 'business_id' => $w->business_id, 'action' => 'WITHDRAWAL_'.$to, 'entity_type' => 'BusinessWithdrawal', 'entity_id' => (string) $w->id, 'metadata' => json_encode(['fromStatus' => $from, 'reason' => $reason, 'provider' => 'MOCK']), 'created_at' => now()]);
        $owner = DB::table('role_assignments')->where('business_id', $w->business_id)->where('role_name', 'BUSINESS_OWNER')->value('user_id');
        if ($owner) app(BookingNotificationService::class)->record((int) $owner, 'WITHDRAWAL_UPDATED', 'Yêu cầu rút tiền #'.$w->id.': '.$to, number_format((int) $w->amount_vnd, 0, ',', '.').'₫ (mô phỏng, nhà cung cấp MOCK)', null, 'withdrawal:'.$w->id.':'.DB::table('withdrawal_events')->where('withdrawal_id', $w->id)->count(), 'INFO', ['withdrawalId' => $w->id, 'status' => $to]);
    }

    public function listFor(Request $r, int $businessId, bool $admin, string $scope = 'LIVE_LAB'): array
    {
        $this->authorize($r, $businessId, $admin);
        $rows = DB::table('business_withdrawals')->where('business_id', $businessId)->where('wallet_scope', $scope)->orderByDesc('id')->limit(50)->get();

        return ['simulation' => true, 'provider' => 'MOCK', 'walletScope' => $scope, 'balances' => array_map('strval', $this->balances($businessId, $scope)), 'items' => $rows->map(fn ($w) => $this->present($w))->all()];
    }

    public function listAll(Request $r): array
    {
        $this->scope->platform($r);
        $query = DB::table('business_withdrawals')->orderByDesc('id');
        if ($r->filled('status') && in_array($r->query('status'), self::STATUSES, true)) $query->where('status', $r->query('status'));

        return ['simulation' => true, 'provider' => 'MOCK', 'items' => $query->limit(100)->get()->map(fn ($w) => $this->present($w) + ['businessName' => DB::table('businesses')->where('id', $w->business_id)->value('name')])->all()];
    }

    private function authorize(Request $r, int $businessId, bool $admin): void
    {
        if ($admin) $this->scope->platform($r);
        else $this->scope->owner($r, $businessId);
    }

    public function request(Request $r, int $businessId, string $key, int $amount, int $accountId, string $scope = 'LIVE_LAB'): array
    {
        $this->scope->owner($r, $businessId);
        $user = $r->user()->id;
        $operationKey = hash('sha256', 'withdrawal-request:'.$user.':'.$key);
        $fingerprint = hash('sha256', json_encode([$businessId, (string) $amount, $accountId, $scope]));

        return DB::transaction(function () use ($r, $businessId, $amount, $accountId, $scope, $user, $operationKey, $fingerprint) {
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            // Authority is re-checked under the business lock so a revoked owner cannot slip a request through.
            $this->scope->owner($r, $businessId);
            $prior = DB::table('business_withdrawals')->where('operation_key', $operationKey)->first();
            if ($prior) {
                abort_unless($prior->fingerprint === $fingerprint, 409, 'Khóa yêu cầu đã dùng cho nội dung khác.');

                return $this->present($prior);
            }
            $account = DB::table('business_bank_accounts')->where('id', $accountId)->where('business_id', $businessId)->where('status', 'VERIFIED_DEMO')->first();
            abort_unless($account, 409, 'Hãy chọn tài khoản ngân hàng đã được duyệt của doanh nghiệp.');
            $payables = $this->payableRows($businessId, $scope, true);
            abort_if($amount > max(0, (int) $payables->sum('remaining')), 409, 'Số dư có thể nhận không đủ.');
            $id = DB::table('business_withdrawals')->insertGetId(['business_id' => $businessId, 'bank_account_id' => $account->id, 'wallet_scope' => $scope, 'operation_key' => $operationKey, 'fingerprint' => $fingerprint, 'amount_vnd' => $amount,
                'status' => 'PENDING_APPROVAL', 'version' => 0, 'requested_by' => $user, 'reason' => 'OWNER_REQUESTED', 'created_at' => now(), 'updated_at' => now(),
                'bank_snapshot' => json_encode(['accountId' => $account->id, 'bankCode' => $account->bank_code, 'bankName' => $account->bank_name, 'accountNumber' => $account->account_number, 'accountName' => $account->account_name, 'verification' => $account->status])]);
            $left = $amount;
            foreach ($payables as $payable) {
                if ($left === 0) break;
                $take = min($left, (int) $payable->remaining);
                if ($take <= 0) continue;
                DB::table('withdrawal_allocations')->insert(['withdrawal_id' => $id, 'payable_id' => $payable->id, 'amount_vnd' => $take, 'released' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $left -= $take;
            }
            abort_unless($left === 0, 409, 'Số dư thay đổi, hãy thử lại.');
            $withdrawal = DB::table('business_withdrawals')->find($id);
            $this->event($withdrawal, null, 'PENDING_APPROVAL', 'OWNER_REQUESTED', $user);

            return $this->present($withdrawal);
        }, 3);
    }

    /** @param 'approve'|'reject'|'retry'|'cancel' $action */
    public function review(Request $r, int $id, string $action, int $expectedVersion, ?string $reason): array
    {
        $ref = DB::table('business_withdrawals')->find($id);
        abort_unless($ref, 404);
        $admin = $action !== 'cancel';
        $this->authorize($r, (int) $ref->business_id, $admin);
        $decision = DB::transaction(function () use ($r, $id, $action, $expectedVersion, $reason, $admin, $ref) {
            // The business lock comes first, before any non-locking read, so this transaction's snapshot includes
            // every refund and reversal that committed ahead of it.
            DB::table('businesses')->where('id', $ref->business_id)->lockForUpdate()->first();
            $row = DB::table('business_withdrawals')->where('id', $id)->lockForUpdate()->first();
            $this->authorize($r, (int) $row->business_id, $admin);
            // A lost approval response can safely resume the command that is already approved or finished.
            if (in_array($action, ['approve', 'retry'], true) && $row->status === 'SIMULATED_COMPLETED') return $row;
            if ($action === 'approve' && $row->status === 'PROCESSING') return $row;
            abort_unless((int) $row->version === $expectedVersion, 409, 'Yêu cầu đã thay đổi. Hãy tải lại.');
            $allowed = match ($action) { 'approve', 'reject' => $row->status === 'PENDING_APPROVAL', 'retry' => $row->status === 'FAILED', 'cancel' => in_array($row->status, ['PENDING_APPROVAL', 'FAILED'], true) };
            abort_unless($allowed, 409, 'Trạng thái yêu cầu không cho phép thao tác này.');
            if ($action === 'retry') abort_if(DB::table('withdrawal_attempts')->where('withdrawal_id', $id)->count() >= 50, 409, 'Đã vượt số lần thử lại.');
            if (in_array($action, ['approve', 'retry'], true)) abort_unless($this->fundsIntact((int) $row->business_id, $row), 409, 'Nguồn tiền đã thay đổi do hoàn tiền hoặc đảo giao dịch. Hãy từ chối hoặc hủy yêu cầu rồi tạo lại.');
            if (in_array($action, ['reject', 'cancel'], true)) abort_if(! $reason || mb_strlen($reason) > 500, 422, 'Cần nêu lý do.');
            $status = match ($action) { 'reject' => 'REJECTED', 'cancel' => 'CANCELLED', default => 'PROCESSING' };
            $text = in_array($action, ['reject', 'cancel'], true) ? $reason : ($action === 'retry' ? 'ADMIN_RETRY' : 'ADMIN_APPROVED');
            if (in_array($status, ['REJECTED', 'CANCELLED'], true)) DB::table('withdrawal_allocations')->where('withdrawal_id', $id)->where('released', 0)->update(['released' => 1, 'updated_at' => now()]);
            DB::table('business_withdrawals')->where('id', $id)->update(['status' => $status, 'reason' => $text, 'version' => $row->version + 1, 'updated_at' => now(),
                ...($admin ? ['reviewed_by' => $r->user()->id, 'reviewed_at' => now('UTC')] : [])]);
            $this->event($row, $row->status, $status, $text, $r->user()->id);

            return DB::table('business_withdrawals')->find($id);
        }, 3);
        // Approval is durable before the adapter runs, so recovery can resume PROCESSING after a restart.
        if ($decision->status === 'PROCESSING') $this->process($id, $r->user()->id);

        return $this->present(DB::table('business_withdrawals')->find($id));
    }

    public function process(int $id, ?int $actorId = null): void
    {
        $ref = DB::table('business_withdrawals')->find($id);
        if (! $ref) return;
        DB::transaction(function () use ($id, $actorId, $ref) {
            DB::table('businesses')->where('id', $ref->business_id)->lockForUpdate()->first();
            $row = DB::table('business_withdrawals')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'PROCESSING') return;
            // Approval may be old (recovery after a crash). Re-check the source money under the lock and never
            // call the provider for funds that were refunded or reversed since the request was made.
            if (! $this->fundsIntact((int) $row->business_id, $row)) {
                DB::table('business_withdrawals')->where('id', $id)->update(['status' => 'FAILED', 'reason' => 'SOURCE_FUNDS_CHANGED', 'version' => $row->version + 1, 'updated_at' => now()]);
                $this->event($row, $row->status, 'FAILED', 'SOURCE_FUNDS_CHANGED', $actorId);

                return;
            }
            $sequence = DB::table('withdrawal_attempts')->where('withdrawal_id', $id)->count() + 1;
            try {
                // Resolved per call so a controller instance cached on the route never pins an old adapter.
                $result = app(MockPayoutProvider::class)->execute($id, (int) $row->amount_vnd, json_decode($row->bank_snapshot, true));
            } catch (\Throwable) {
                $result = ['success' => false, 'errorCode' => 'MOCK_PROVIDER_ERROR'];
            }
            $ok = $result['success'] === true;
            DB::table('withdrawal_attempts')->insert(['withdrawal_id' => $id, 'sequence' => $sequence, 'provider' => 'MOCK', 'status' => $ok ? 'SUCCESS' : 'FAILED', 'reference' => $ok ? $result['reference'] : null, 'error_code' => $ok ? null : $result['errorCode'], 'created_at' => now(), 'updated_at' => now()]);
            // The unique operation key makes a replayed completion post the payout exactly once.
            if ($ok) \App\Services\Wallet\WalletCore::post(['operationKey' => 'withdrawal:'.$id, 'kind' => 'SETTLEMENT', 'businessId' => (int) $row->business_id, 'amount' => (int) $row->amount_vnd, 'actorId' => $actorId, 'reason' => 'MOCK_PAYOUT_COMPLETED']);
            $status = $ok ? 'SIMULATED_COMPLETED' : 'FAILED';
            $text = $ok ? 'MOCK_PAYOUT_COMPLETED' : 'MOCK_PAYOUT_FAILED';
            DB::table('business_withdrawals')->where('id', $id)->update(['status' => $status, 'reason' => $text, 'version' => $row->version + 1, 'completed_at' => $ok ? now('UTC') : null, 'updated_at' => now()]);
            $this->event($row, $row->status, $status, $text, $actorId);
        }, 3);
    }

    /** Resume approved withdrawals that were interrupted before the adapter ran. */
    public function recover(int $limit = 20): int
    {
        $done = 0;
        foreach (DB::table('business_withdrawals')->where('status', 'PROCESSING')->orderBy('updated_at')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $this->process($id);
            $done++;
        }

        return $done;
    }
}
