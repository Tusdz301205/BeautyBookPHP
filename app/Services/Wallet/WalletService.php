<?php

namespace App\Services\Wallet;

use App\Services\Beautybook\ApiScope;
use App\Services\Booking\BookingNotificationService;
use App\Services\Payout\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer wallet, LIVE_LAB top-up invoices, business bank accounts and the admin reconciliation view (wallet.service.ts).
 * Authority is checked before and again inside the transaction; money is returned as strings.
 */
class WalletService
{
    public function __construct(private ApiScope $scope, private WithdrawalService $withdrawals) {}

    public static function financialKey(mixed $key): string
    {
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $key)) abort(422, 'Invalid payment field');

        return $key;
    }

    private static function text(mixed $value, int $max, ?string $pattern = null): string
    {
        if (! is_string($value)) abort(422, 'Invalid payment field');
        $s = trim(\Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value);
        if ($s === '' || mb_strlen($s) > $max || preg_match('/[\x00-\x1f]/', $s) || ($pattern && ! preg_match($pattern, $s))) abort(422, 'Invalid payment field');

        return $s;
    }

    private function iso(?string $utc): ?string
    {
        return $utc ? \Carbon\CarbonImmutable::parse($utc, 'UTC')->toISOString() : null;
    }

    private function presentTopUp(object $t, int $userId, bool $live, array $receipts = []): array
    {
        return ['id' => (string) $t->id, 'code' => $t->code, 'provider' => $t->provider, 'amountVnd' => (string) $t->amount_vnd, 'status' => $t->status, 'expiresAt' => $this->iso($t->expires_at),
            'completedAt' => $this->iso($t->completed_at), 'createdAt' => \Carbon\CarbonImmutable::parse($t->created_at, config('app.timezone'))->utc()->toISOString(), 'receiverSnapshot' => json_decode((string) $t->receiver_snapshot, true),
            'qrUrl' => $live ? LiveWalletPolicy::qr($t, $userId) : null, 'receipts' => $receipts];
    }

    // ---- customer ---------------------------------------------------------------------------------------------

    public function mine(Request $r, int $page, ?string $requestedScope): array
    {
        $profile = $this->scope->customer($r);
        $scope = $requestedScope ?? 'LIVE_LAB';
        $userId = (int) $r->user()->id;

        return DB::transaction(function () use ($profile, $scope, $page, $userId) {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            DB::table('customer_profiles')->where('id', $profile->id)->lockForUpdate()->first();
            $wallet = WalletCore::lock((int) $profile->id, $scope);
            DB::table('wallet_topups')->where('wallet_id', $wallet->id)->where('provider', '!=', 'SEPAY_LIVE')->where('status', 'PENDING')->where('expires_at', '<=', now('UTC'))->update(['status' => 'EXPIRED', 'updated_at' => now()]);
            if ($scope === 'LIVE_LAB') {
                foreach (DB::table('wallet_topups')->where('wallet_id', $wallet->id)->where('provider', 'SEPAY_LIVE')->where('status', 'PENDING')->where('expires_at', '<=', now('UTC'))->limit(20)->pluck('id') as $id) LiveWalletInbox::expire((int) $id);
            }
            $history = DB::table('wallet_transfers')->where('wallet_id', $wallet->id)->orderByDesc('created_at')->orderByDesc('id')->forPage($page, 20)->get()
                ->map(fn ($x) => ['id' => (string) $x->id, 'kind' => $x->kind, 'amountVnd' => (string) $x->amount_vnd, 'reason' => $x->reason, 'availableAfterVnd' => $x->available_after_vnd === null ? null : (string) $x->available_after_vnd,
                    'heldAfterVnd' => $x->held_after_vnd === null ? null : (string) $x->held_after_vnd, 'createdAt' => $this->iso($x->created_at)])->all();
            $live = $scope === 'LIVE_LAB';
            $topUps = DB::table('wallet_topups')->where('wallet_id', $wallet->id)->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get()->map(function ($t) use ($userId, $live) {
                $receipts = DB::table('sepay_webhook_events')->where('top_up_id', $t->id)->where('processing', 'MANUAL_REVIEW')->get(['id', 'amount_vnd', 'review_reason', 'received_at'])
                    ->map(fn ($e) => ['id' => (string) $e->id, 'amountVnd' => (string) $e->amount_vnd, 'reviewReason' => $e->review_reason, 'receivedAt' => $this->iso($e->received_at)])->all();

                return $this->presentTopUp($t, $userId, $live, $receipts);
            })->all();
            $enabled = LiveWalletPolicy::available($userId);

            return ['simulation' => ! $live, 'internalTest' => true, 'wallet' => ['id' => (string) $wallet->id, 'scope' => $wallet->scope, 'availableVnd' => (string) $wallet->available_vnd, 'heldVnd' => (string) $wallet->held_vnd, 'version' => (int) $wallet->version],
                'history' => $history, 'topUps' => $topUps, 'total' => DB::table('wallet_transfers')->where('wallet_id', $wallet->id)->count(), 'page' => $page, 'limit' => 20,
                'liveLabEligible' => LiveWalletPolicy::liveLabUser($userId), 'liveLabAvailable' => $live && $enabled, 'liveLabLimits' => $enabled ? array_map('strval', LiveWalletPolicy::limits()) : null];
        }, 3);
    }

    public function topUpStatus(Request $r, int $id): array
    {
        $profile = $this->scope->customer($r);
        $userId = (int) $r->user()->id;
        $topUp = DB::table('wallet_topups as t')->join('wallets as w', 'w.id', '=', 't.wallet_id')->where('t.id', $id)->where('w.customer_profile_id', $profile->id)->first(['t.*']);
        abort_unless($topUp, 404, 'Top-up not found');
        $receipts = DB::table('sepay_webhook_events')->where('top_up_id', $id)->get(['id', 'processing', 'review_reason', 'amount_vnd'])
            ->map(fn ($e) => ['id' => (string) $e->id, 'processing' => $e->processing, 'reviewReason' => $e->review_reason, 'amountVnd' => (string) $e->amount_vnd])->all();

        return ['internalTest' => true, 'simulation' => $topUp->provider !== 'SEPAY_LIVE', 'topUp' => $this->presentTopUp($topUp, $userId, true, $receipts)];
    }

    public function topUp(Request $r, mixed $key, array $raw): array
    {
        if (array_diff(array_keys($raw), ['amountVnd', 'provider'])) abort(400, 'Invalid top-up fields');
        $text = self::text($raw['amountVnd'] ?? null, 10, '/^[1-9][0-9]*$/');
        $amount = (int) $text;
        if ($amount > 1000000000) abort(400, 'Top-up limit exceeded');
        if (($raw['provider'] ?? null) !== 'SEPAY_LIVE') abort(400, 'Only SePay Live top-ups are supported');
        $userId = (int) $r->user()->id;
        $profile = $this->scope->customer($r);
        $receiver = LiveWalletPolicy::receiverSnapshot(LiveWalletPolicy::requireUser($userId));
        $keyHash = WalletCore::hash(['actor' => $userId, 'key' => self::financialKey($key)]);
        $fingerprint = WalletCore::hash(['provider' => 'SEPAY_LIVE', 'amount' => $text]);
        $id = DB::transaction(function () use ($userId, $profile, $keyHash, $fingerprint, $amount, $receiver) {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            DB::table('customer_profiles')->where('id', $profile->id)->lockForUpdate()->first();
            $wallet = WalletCore::lock((int) $profile->id, 'LIVE_LAB');
            $prior = DB::table('wallet_topups')->where('wallet_id', $wallet->id)->where('key_hash', $keyHash)->first();
            if ($prior) {
                abort_unless($prior->fingerprint === $fingerprint, 409, 'Top-up key already used with different terms');

                return (int) $prior->id;
            }
            LiveWalletPolicy::requireUser($userId);
            $limits = LiveWalletPolicy::limits();
            $committed = (int) DB::table('wallet_topups')->where('wallet_id', $wallet->id)->where(fn ($q) => $q->where('status', 'SUCCESS')->orWhere(fn ($q) => $q->where('status', 'PENDING')->where('expires_at', '>', now('UTC'))))->sum('amount_vnd');
            if ($amount < $limits['minimum'] || $amount > $limits['maximum'] || $committed + $amount > $limits['total']) abort(400, 'Private Live test top-up limit exceeded');
            $created = DB::table('wallet_topups')->insertGetId(['wallet_id' => $wallet->id, 'key_hash' => $keyHash, 'fingerprint' => $fingerprint, 'code' => 'WL'.strtoupper(bin2hex(random_bytes(12))), 'amount_vnd' => $amount, 'provider' => 'SEPAY_LIVE',
                'expires_at' => now('UTC')->addMinutes(15)->format('Y-m-d H:i:s'), 'receiver_snapshot' => json_encode($receiver), 'created_at' => now(), 'updated_at' => now()]);
            app(BookingNotificationService::class)->record($userId, 'WALLET_TOPUP_PENDING', 'Đã tạo yêu cầu nạp ví', 'Chuyển khoản đúng số tiền và nội dung trong 15 phút (Live lab).', null, 'live-pending:'.$created, 'INFO', ['simulation' => true]);

            return $created;
        }, 3);
        $row = DB::table('wallet_topups')->find($id);

        return ['simulation' => false, 'internalTest' => true, 'topUp' => $this->presentTopUp($row, $userId, true), 'receiver' => $receiver];
    }

    // ---- business owner ---------------------------------------------------------------------------------------

    private function presentAccount(object $a): array
    {
        return ['id' => (string) $a->id, 'bankCode' => $a->bank_code, 'bankName' => $a->bank_name, 'accountNumberMasked' => str_repeat('•', max(0, strlen($a->account_number) - 4)).substr($a->account_number, -4),
            'accountName' => $a->account_name, 'status' => $a->status, 'isDefault' => (bool) $a->is_default, 'version' => (int) $a->version];
    }

    public function businessSummary(Request $r, int $businessId, ?string $scope): array
    {
        $this->scope->owner($r, $businessId);
        $scope ??= 'LIVE_LAB';

        return DB::transaction(function () use ($r, $businessId, $scope) {
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            $this->scope->owner($r, $businessId);
            $accounts = DB::table('business_bank_accounts')->where('business_id', $businessId)->orderByDesc('created_at')->limit(50)->get()->map(fn ($a) => $this->presentAccount($a))->all();
            $payables = DB::table('business_payables as p')->join('booking_deposits as d', 'd.id', '=', 'p.deposit_id')->join('wallets as w', 'w.id', '=', 'd.wallet_id')->join('bookings as b', 'b.id', '=', 'd.booking_id')
                ->where('p.business_id', $businessId)->where('w.scope', $scope)->orderByDesc('p.created_at')->limit(100)
                ->get(['p.id', 'p.amount_vnd', 'p.reason', 'p.created_at', 'b.booking_code', 'd.status as deposit_status', 'p.settlement_id'])
                ->map(fn ($p) => ['id' => (string) $p->id, 'amountVnd' => (string) $p->amount_vnd, 'reason' => $p->reason, 'bookingCode' => $p->booking_code, 'depositStatus' => $p->deposit_status, 'createdAt' => $this->iso($p->created_at), 'settlementId' => $p->settlement_id ? (string) $p->settlement_id : null,
                    'allocatedVnd' => (string) DB::table('withdrawal_allocations')->where('payable_id', $p->id)->where('released', 0)->sum('amount_vnd')])->all();
            $held = (int) DB::table('booking_deposits as d')->join('wallets as w', 'w.id', '=', 'd.wallet_id')->join('bookings as b', 'b.id', '=', 'd.booking_id')->where('d.funding_source', 'SIMULATED_WALLET')->where('d.status', 'HELD')->where('w.scope', $scope)->where('b.business_id', $businessId)->sum('d.amount_vnd');

            return ['simulation' => true, 'walletScope' => $scope, 'walletMode' => LiveWalletPolicy::walletMode(), 'withdrawalBalances' => array_map('strval', $this->withdrawals->balances($businessId, $scope)),
                'accounts' => $accounts, 'payables' => $payables, 'heldVnd' => (string) $held,
                'settlements' => DB::table('sim_settlements')->where('business_id', $businessId)->where('wallet_scope', $scope)->orderByDesc('id')->limit(50)->get()->map(fn ($x) => $this->presentSettlement($x))->all()];
        }, 3);
    }

    private function presentSettlement(object $s): array
    {
        return ['id' => (string) $s->id, 'businessId' => (string) $s->business_id, 'walletScope' => $s->wallet_scope, 'status' => $s->status, 'amountVnd' => (string) $s->amount_vnd, 'bankSnapshot' => json_decode((string) $s->bank_snapshot, true),
            'periodStart' => $this->iso($s->period_start), 'periodEnd' => $this->iso($s->period_end), 'completedAt' => $this->iso($s->completed_at), 'createdAt' => \Carbon\CarbonImmutable::parse($s->created_at, config('app.timezone'))->utc()->toISOString()];
    }

    private function accountData(array $raw, bool $withVersion): array
    {
        $allowed = ['bankCode', 'bankName', 'accountNumber', 'accountName'] + ($withVersion ? [4 => 'version'] : []);
        if (array_diff(array_keys($raw), $allowed)) abort(400, 'Invalid bank account');
        if ($withVersion && (! isset($raw['version']) || ! is_int($raw['version']) || $raw['version'] < 0)) abort(400, 'Current account version required');

        return ['bank_code' => self::text($raw['bankCode'] ?? null, 32), 'bank_name' => self::text($raw['bankName'] ?? null, 100), 'account_number' => self::text($raw['accountNumber'] ?? null, 64, '/^[A-Za-z0-9_-]{3,64}$/'), 'account_name' => self::text($raw['accountName'] ?? null, 120)];
    }

    /** The owner submits an account for review; it can be withdrawn to only after a platform admin verifies it. */
    public function addBank(Request $r, int $businessId, array $raw): array
    {
        $data = $this->accountData($raw, false);
        $this->scope->owner($r, $businessId);

        return DB::transaction(function () use ($r, $businessId, $data) {
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            $this->scope->owner($r, $businessId);
            $prior = DB::table('business_bank_accounts')->where('business_id', $businessId)->where($data)->first();
            if ($prior) return $this->presentAccount($prior);
            abort_if(DB::table('business_bank_accounts')->where('business_id', $businessId)->count() >= 50, 409, 'Bank account limit reached');
            $id = DB::table('business_bank_accounts')->insertGetId($data + ['business_id' => $businessId, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, $businessId, 'SETTLEMENT_BANK_SUBMITTED', $id, []);

            return $this->presentAccount(DB::table('business_bank_accounts')->find($id));
        }, 3);
    }

    /** A replacement is a new pending account; the previous one stays usable until the new one is verified. */
    public function replaceBank(Request $r, int $businessId, int $id, array $raw): array
    {
        $data = $this->accountData($raw, true);
        unset($data['version']);
        $this->scope->owner($r, $businessId);

        return DB::transaction(function () use ($r, $businessId, $id, $data, $raw) {
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            $this->scope->owner($r, $businessId);
            $original = DB::table('business_bank_accounts')->where('id', $id)->where('business_id', $businessId)->first();
            abort_unless($original, 404, 'Account not found');
            abort_unless((int) $original->version === $raw['version'], 409, 'Bank account changed');
            $duplicate = DB::table('business_bank_accounts')->where('business_id', $businessId)->where($data)->first();
            if ($duplicate) return $this->presentAccount($duplicate);
            abort_if(DB::table('business_bank_accounts')->where('business_id', $businessId)->count() >= 50, 409, 'Bank account limit reached');
            $new = DB::table('business_bank_accounts')->insertGetId($data + ['business_id' => $businessId, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, $businessId, 'SETTLEMENT_BANK_REPLACEMENT_SUBMITTED', $new, ['previousAccountId' => $id]);

            return $this->presentAccount(DB::table('business_bank_accounts')->find($new));
        }, 3);
    }

    public function verifyBank(Request $r, int $businessId, int $id, int $version): array
    {
        $this->scope->platform($r);

        return DB::transaction(function () use ($r, $businessId, $id, $version) {
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            $this->scope->platform($r);
            $row = DB::table('business_bank_accounts')->where('id', $id)->where('business_id', $businessId)->first();
            abort_unless($row, 404, 'Account not found');
            abort_unless((int) $row->version === $version, 409, 'Account changed');
            DB::table('business_bank_accounts')->where('business_id', $businessId)->where('is_default', 1)->update(['is_default' => 0, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('business_bank_accounts')->where('id', $id)->update(['status' => 'VERIFIED_DEMO', 'is_default' => 1, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->audit($r, $businessId, 'BANK_VERIFIED_SIMULATION_ONLY', $id, []);

            return $this->presentAccount(DB::table('business_bank_accounts')->find($id));
        }, 3);
    }

    private function audit(Request $r, ?int $businessId, string $action, int $entityId, array $metadata): void
    {
        DB::table('audit_logs')->insert(['user_id' => $r->user()->id, 'business_id' => $businessId, 'action' => $action, 'entity_type' => 'BusinessBankAccount', 'entity_id' => (string) $entityId, 'metadata' => json_encode($metadata + ['simulation' => true]), 'created_at' => now()]);
    }

    // ---- platform admin ---------------------------------------------------------------------------------------

    public function adminOverview(Request $r, int $page): array
    {
        $this->scope->platform($r);
        $limit = 50;
        $page = max(1, $page);
        $map = fn ($rows, callable $f) => $rows->map($f)->all();

        return [
            'simulation' => true, 'internalTest' => true, 'page' => $page,
            'topUps' => $map(DB::table('wallet_topups')->orderByDesc('created_at')->forPage($page, $limit)->get(), fn ($t) => ['id' => (string) $t->id, 'code' => $t->code, 'amountVnd' => (string) $t->amount_vnd, 'status' => $t->status, 'expiresAt' => $this->iso($t->expires_at)]),
            'deposits' => $map(DB::table('booking_deposits')->where('funding_source', 'SIMULATED_WALLET')->orderByDesc('created_at')->forPage($page, $limit)->get(), fn ($d) => ['id' => (string) $d->id, 'bookingId' => (string) $d->booking_id, 'amountVnd' => (string) $d->amount_vnd, 'status' => $d->status]),
            'settlements' => $map(DB::table('sim_settlements as s')->join('businesses as b', 'b.id', '=', 's.business_id')->orderByDesc('s.id')->forPage($page, $limit)->get(['s.*', 'b.name as business_name']), fn ($x) => $this->presentSettlement($x) + ['businessName' => $x->business_name]),
            'accounts' => $map(DB::table('business_bank_accounts as a')->join('businesses as b', 'b.id', '=', 'a.business_id')->orderByDesc('a.created_at')->forPage($page, $limit)->get(['a.*', 'b.name as business_name']),
                fn ($a) => $this->presentAccount($a) + ['businessId' => (string) $a->business_id, 'businessName' => $a->business_name]),
            'webhooks' => $map(DB::table('sepay_webhook_events')->whereIn('processing', ['UNMATCHED', 'REFUND_REQUIRED', 'MANUAL_REVIEW', 'RETRY_PENDING'])->orderByDesc('received_at')->forPage($page, $limit)->get(),
                fn ($e) => ['id' => (string) $e->id, 'environment' => $e->environment, 'channel' => $e->channel, 'reviewReason' => $e->review_reason, 'topUpId' => $e->top_up_id ? (string) $e->top_up_id : null, 'providerTransactionId' => (string) $e->provider_transaction_id,
                    'amountVnd' => (string) $e->amount_vnd, 'processing' => $e->processing, 'receivedAt' => $this->iso($e->received_at)]),
        ];
    }

    /**
     * Gathers the business's released payables of a period into one settlement (createSettlement). A payable qualifies
     * only while it is whole: unsettled, no open allocation and no refund recorded against its deposit. The business lock
     * is taken first, like every withdrawal, so a request and a settlement can never draw on the same money.
     */
    public function createSettlement(Request $r, mixed $key, array $raw): array
    {
        $this->scope->platform($r);
        if (array_diff(array_keys($raw), ['businessId', 'accountId', 'periodStart', 'periodEnd', 'walletScope'])) abort(400, 'Invalid settlement fields');
        $key = self::financialKey($key);
        $businessId = (int) self::text($raw['businessId'] ?? null, 20, '/^[1-9][0-9]*$/');
        $accountId = (int) self::text($raw['accountId'] ?? null, 20, '/^[1-9][0-9]*$/');
        try {
            $start = \Carbon\CarbonImmutable::parse(self::text($raw['periodStart'] ?? null, 40));
            $end = \Carbon\CarbonImmutable::parse(self::text($raw['periodEnd'] ?? null, 40));
        } catch (\Throwable) {
            abort(400, 'Invalid settlement period');
        }
        if ($end->lessThanOrEqualTo($start) || $end->diffInSeconds($start, true) > 366 * 86400) abort(400, 'Invalid settlement period');
        $scope = LiveWalletPolicy::scope($raw['walletScope'] ?? null) ?? 'LIVE_LAB';
        $actorId = (int) $r->user()->id;
        $operationKey = 'settlement:'.WalletCore::hash(['actor' => $actorId, 'key' => $key]);
        $fingerprint = WalletCore::hash([$businessId, $accountId, $start->utc()->toIso8601String(), $end->utc()->toIso8601String(), $scope]);

        return DB::transaction(function () use ($r, $businessId, $accountId, $start, $end, $scope, $actorId, $operationKey, $fingerprint) {
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            $this->scope->platform($r);
            $prior = DB::table('sim_settlements')->where('operation_key', $operationKey)->first();
            if ($prior) {
                abort_unless($prior->fingerprint === $fingerprint, 409, 'Settlement key identity conflict');

                return $this->presentSettlement($prior);
            }
            $account = DB::table('business_bank_accounts')->where('id', $accountId)->where('business_id', $businessId)->where('status', 'VERIFIED_DEMO')->where('is_default', 1)->first();
            abort_unless($account, 409, 'A verified default demo bank account is required');
            $from = $start->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $to = $end->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $items = collect(DB::select('SELECT p.id, p.amount_vnd FROM business_payables p JOIN booking_deposits d ON d.id = p.deposit_id JOIN wallets w ON w.id = d.wallet_id
                WHERE p.business_id = ? AND p.settlement_id IS NULL AND w.scope = ? AND p.created_at >= ? AND p.created_at < ?
                  AND NOT EXISTS (SELECT 1 FROM withdrawal_allocations a WHERE a.payable_id = p.id AND a.released = 0)
                  AND NOT EXISTS (SELECT 1 FROM deposit_refunds f WHERE f.deposit_id = p.deposit_id AND f.status IN (\'PENDING\', \'CONFIRMED\'))
                ORDER BY p.id FOR UPDATE OF p', [$businessId, $scope, $from, $to]));
            abort_if($items->isEmpty(), 409, 'No eligible unsettled deposits in this period');
            $amount = (int) $items->sum('amount_vnd');
            $id = DB::table('sim_settlements')->insertGetId(['business_id' => $businessId, 'wallet_scope' => $scope, 'operation_key' => $operationKey, 'fingerprint' => $fingerprint, 'status' => 'PENDING', 'amount_vnd' => $amount,
                'bank_snapshot' => json_encode(['bankCode' => $account->bank_code, 'bankName' => $account->bank_name, 'accountNumber' => $account->account_number, 'accountName' => $account->account_name, 'accountId' => (int) $account->id, 'verification' => 'VERIFIED_DEMO']),
                'period_start' => $start->utc()->format('Y-m-d H:i:s'), 'period_end' => $end->utc()->format('Y-m-d H:i:s'), 'actor_id' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('business_payables')->whereIn('id', $items->pluck('id')->all())->whereNull('settlement_id')->update(['settlement_id' => $id, 'updated_at' => now()]);
            $owner = (int) DB::table('role_assignments')->where('business_id', $businessId)->where('role_name', 'BUSINESS_OWNER')->value('user_id');
            app(BookingNotificationService::class)->record($owner, 'SIMULATED_SETTLEMENT_CREATED', 'Đã tạo đợt đối soát mô phỏng', 'Đợt đối soát '.number_format($amount, 0, ',', '.').'₫ đang chờ hoàn tất. Đây là mô phỏng, không có chuyển khoản thật.', null, 'settlement-created:'.$id, 'INFO', ['simulation' => true]);
            DB::table('audit_logs')->insert(['user_id' => $actorId, 'business_id' => $businessId, 'action' => 'SIMULATED_SETTLEMENT_CREATED', 'entity_type' => 'SimSettlement', 'entity_id' => (string) $id, 'metadata' => json_encode(['amountVnd' => (string) $amount, 'simulation' => true]), 'created_at' => now()]);
            $this->scope->platform($r);

            return $this->presentSettlement(DB::table('sim_settlements')->find($id));
        }, 3);
    }

    /** Marks a settlement paid: one SETTLEMENT ledger entry (payable -> settled), no bank transfer. Replays return the same record. */
    public function completeSettlement(Request $r, int $id): array
    {
        $this->scope->platform($r);
        $ref = DB::table('sim_settlements')->where('id', $id)->first();
        abort_unless($ref, 404, 'Settlement not found');

        return DB::transaction(function () use ($r, $id, $ref) {
            DB::table('businesses')->where('id', $ref->business_id)->lockForUpdate()->first();
            $this->scope->platform($r);
            $row = DB::table('sim_settlements')->where('id', $id)->lockForUpdate()->first();
            if ($row->status === 'SIMULATED_PAID') return $this->presentSettlement($row);
            WalletCore::post(['operationKey' => 'settled:'.$id, 'kind' => 'SETTLEMENT', 'businessId' => (int) $row->business_id, 'amount' => (int) $row->amount_vnd, 'actorId' => (int) $r->user()->id, 'reason' => 'SIMULATED_SETTLEMENT_NO_BANK_TRANSFER']);
            DB::table('sim_settlements')->where('id', $id)->update(['status' => 'SIMULATED_PAID', 'completed_at' => now('UTC')->format('Y-m-d H:i:s'), 'updated_at' => now()]);
            $owner = (int) DB::table('role_assignments')->where('business_id', $row->business_id)->where('role_name', 'BUSINESS_OWNER')->value('user_id');
            app(BookingNotificationService::class)->record($owner, 'SIMULATED_SETTLEMENT_COMPLETED', 'Đối soát mô phỏng đã hoàn tất', 'Đợt đối soát '.number_format((int) $row->amount_vnd, 0, ',', '.').'₫ đã được đánh dấu hoàn tất (mô phỏng, không có chuyển khoản thật).', null, 'wallet-settlement:'.$id, 'INFO', ['simulation' => true]);
            DB::table('audit_logs')->insert(['user_id' => (int) $r->user()->id, 'business_id' => (int) $row->business_id, 'action' => 'SIMULATED_SETTLEMENT_COMPLETED', 'entity_type' => 'SimSettlement', 'entity_id' => (string) $id, 'metadata' => json_encode(['simulation' => true]), 'created_at' => now()]);
            $this->scope->platform($r);

            return $this->presentSettlement(DB::table('sim_settlements')->find($id));
        }, 3);
    }

    /** Re-runs the wallet inbox for a Live-lab receipt (for example after the missing invoice or account was fixed). */
    public function reconcileReceipt(Request $r, int $id): array
    {
        $this->scope->platform($r);

        return DB::transaction(function () use ($r, $id) {
            $this->scope->platform($r);
            $receipt = DB::table('sepay_webhook_events')->where('id', $id)->where('channel', 'WALLET_LIVE_LAB')->first();
            abort_unless($receipt, 404, 'Live lab receipt not found');
            $applied = LiveWalletInbox::process($id);
            DB::table('audit_logs')->insert(['user_id' => $r->user()->id, 'business_id' => null, 'action' => 'LIVE_LAB_RECEIPT_RECONCILE', 'entity_type' => 'SepayWebhookEvent', 'entity_id' => (string) $id, 'metadata' => json_encode(['applied' => $applied]), 'created_at' => now()]);

            return ['applied' => $applied];
        }, 3);
    }
}
