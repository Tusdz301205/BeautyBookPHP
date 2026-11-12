<?php

namespace App\Services\Payments;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Services\Beautybook\ApiScope;
use App\Services\Booking\BookingNotificationService;
use App\Services\Booking\CustomerBookingFinalizer;
use App\Services\Finance\PaymentService;
use App\Services\Finance\SepayWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Direct deposits (direct-deposit.{controller,service}.ts): the owner's receiving accounts ("connections"), the
 * per-booking deposit view and its actions, and the signed receipt webhook of one connection. Authority is checked
 * before and again inside the transaction; money is returned as strings; a receipt never pays twice.
 */
class DirectDepositService
{
    public function __construct(private ApiScope $scope, private SepayWebhookService $sepay) {}

    private function iso(?string $utc): ?string
    {
        return $utc ? CarbonImmutable::parse($utc, 'UTC')->toISOString() : null;
    }

    private function gate(): void
    {
        abort_unless(DirectDeposit::enabled(), 503, 'Direct deposits are disabled');
    }

    private static function version(mixed $v): int
    {
        if (! is_int($v) || $v < 0) abort(400, 'A current payment version is required');

        return $v;
    }

    private function audit(Request|int|null $actor, ?int $businessId, ?int $branchId, string $action, string $entityType, int $entityId, array $metadata = []): void
    {
        $userId = $actor instanceof Request ? $actor->user()->id : $actor;
        DB::table('audit_logs')->insert(['user_id' => $userId, 'business_id' => $businessId, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => (string) $entityId, 'metadata' => json_encode($branchId ? $metadata + ['branchId' => $branchId] : $metadata), 'created_at' => now()]);
    }

    /** Locks the business, then re-checks that the caller owns it and that it is approved. */
    private function owner(Request $r, int $businessId): void
    {
        $this->scope->owner($r, $businessId);
        Business::whereKey($businessId)->lockForUpdate()->first();
        $this->scope->owner($r, $businessId);
        abort_unless(Business::whereKey($businessId)->whereIn('status', [BusinessStatus::Approved->value, BusinessStatus::Active->value])->exists(), 403, 'Approved business owner required');
    }

    private function presentConnection(object $c): array
    {
        return ['id' => (string) $c->id, 'businessId' => (string) $c->business_id, 'environment' => $c->environment, 'bankCode' => $c->bank_code, 'accountNumber' => $c->account_number, 'accountName' => $c->account_name,
            'verificationCode' => $c->verification_code, 'status' => $c->status, 'enabled' => (bool) $c->enabled, 'verifiedAt' => $this->iso($c->verified_at), 'version' => (int) $c->version,
            'createdAt' => CarbonImmutable::parse($c->created_at, config('app.timezone'))->utc()->toISOString()];
    }

    // ---- connections (business owner) ---------------------------------------------------------------------------

    public function connections(Request $r, int $businessId): array
    {
        $this->scope->owner($r, $businessId);

        return DB::transaction(function () use ($r, $businessId) {
            $this->owner($r, $businessId);
            $receipts = DB::table('sepay_webhook_events as e')->join('payment_connections as c', 'c.id', '=', 'e.connection_id')->leftJoin('booking_deposits as d', 'd.id', '=', 'e.deposit_id')
                ->where('c.business_id', $businessId)->where('e.transfer_type', 'in')->whereIn('e.processing', ['UNMATCHED', 'REFUND_REQUIRED'])->orderByDesc('e.received_at')->orderByDesc('e.id')->limit(30)
                ->get(['e.id', 'e.provider_transaction_id', 'e.environment', 'e.transaction_at', 'e.amount_vnd', 'e.content', 'e.processing', 'd.booking_id'])
                ->map(fn ($e) => ['id' => (string) $e->id, 'providerTransactionId' => (string) $e->provider_transaction_id, 'environment' => $e->environment, 'transactionAt' => $this->iso($e->transaction_at), 'amountVnd' => (string) $e->amount_vnd,
                    'content' => $e->content, 'processing' => $e->processing, 'bookingId' => $e->booking_id ? (string) $e->booking_id : null])->all();

            return ['enabled' => DirectDeposit::enabled(), 'receipts' => $receipts, 'banks' => DirectDeposit::BANKS,
                'connections' => DB::table('payment_connections')->where('business_id', $businessId)->orderByDesc('id')->limit(30)->get()->map(fn ($c) => $this->presentConnection($c))->all()];
        }, 3);
    }

    public function createConnection(Request $r, int $businessId, array $raw): array
    {
        $this->gate();
        if (array_diff(array_keys($raw), ['bankCode', 'accountNumber', 'accountName', 'environment'])) abort(400, 'Invalid connection fields');
        $bank = DirectDeposit::text($raw['bankCode'] ?? null, 32);
        $number = DirectDeposit::text($raw['accountNumber'] ?? null, 64, '/^[A-Za-z0-9_-]{3,64}$/');
        $name = mb_strtoupper(DirectDeposit::text($raw['accountName'] ?? null, 120, '/^[A-Za-z0-9 ._-]+$/'));
        $environment = $raw['environment'] ?? null;
        if (! in_array($bank, DirectDeposit::BANKS, true) || ! in_array($environment, ['test', 'live'], true)) abort(400, 'Unsupported bank or environment');
        $this->scope->owner($r, $businessId);

        return DB::transaction(function () use ($r, $businessId, $bank, $number, $name, $environment) {
            $this->owner($r, $businessId);
            abort_if(DB::table('payment_connections')->where('business_id', $businessId)->where('status', 'SETUP')->count() >= 5, 409, 'Finish an existing connection setup first');
            $secret = bin2hex(random_bytes(32));
            $id = DB::table('payment_connections')->insertGetId(['business_id' => $businessId, 'environment' => $environment, 'bank_code' => $bank, 'account_number' => $number, 'account_name' => $name,
                'encrypted_secret' => '', 'verification_code' => 'GS'.strtoupper(bin2hex(random_bytes(12))), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('payment_connections')->where('id', $id)->update(['encrypted_secret' => DirectDeposit::seal($secret, $id)]);
            $this->audit($r, $businessId, null, 'PAYMENT_CONNECTION_CREATE', 'PaymentConnection', $id, ['environment' => $environment, 'bankCode' => $bank]);

            return $this->presentConnection(DB::table('payment_connections')->find($id)) + ['secret' => $secret, 'webhookPath' => '/api/v1/payments/sepay/connections/'.$id.'/webhook'];
        }, 3);
    }

    public function enableConnection(Request $r, int $businessId, int $id, array $raw): array
    {
        if (($raw['enabled'] ?? null) === true) $this->gate();
        if (array_diff(array_keys($raw), ['enabled', 'version'])) abort(400, 'Invalid payment fields');
        $version = self::version($raw['version'] ?? null);
        if (! is_bool($raw['enabled'] ?? null)) abort(400, 'Enabled must be boolean');
        $enabled = $raw['enabled'];
        $this->scope->owner($r, $businessId);

        return DB::transaction(function () use ($r, $businessId, $id, $version, $enabled) {
            $this->owner($r, $businessId);
            $row = DB::table('payment_connections')->where('id', $id)->where('business_id', $businessId)->lockForUpdate()->first();
            abort_unless($row, 404, 'Connection not found');
            abort_unless((int) $row->version === $version, 409, 'Connection changed; reload');
            abort_if($enabled && $row->status !== 'READY', 409, 'Receive a signed verification transfer before enabling');
            // Only one connection takes new deposits: enabling this one switches the previous one off for new bookings.
            if ($enabled) DB::table('payment_connections')->where('business_id', $businessId)->where('enabled', 1)->where('id', '!=', $id)->update(['enabled' => 0, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('payment_connections')->where('id', $id)->update(['enabled' => $enabled ? 1 : 0, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->audit($r, $businessId, null, 'PAYMENT_CONNECTION_ENABLE', 'PaymentConnection', $id, ['enabled' => $enabled]);

            return $this->presentConnection(DB::table('payment_connections')->find($id));
        }, 3);
    }

    // ---- a booking's deposit ------------------------------------------------------------------------------------

    /** @return array{booking:object,role:string} the caller's relation to the booking in the active workspace, else 404 */
    private function access(Request $r, int $bookingId): array
    {
        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        abort_unless($booking, 404, 'Booking not found');
        $assignment = $this->scope->current($r);
        $customerUser = (int) DB::table('customer_profiles')->where('id', $booking->customer_profile_id)->value('user_id');
        $same = fn ($a, $b) => (string) $a === (string) $b;
        $role = match (true) {
            $assignment->role_name === 'CUSTOMER' && $customerUser === (int) $r->user()->id => 'CUSTOMER',
            $assignment->role_name === 'BUSINESS_OWNER' && $same($assignment->business_id, $booking->business_id) => 'OWNER',
            $assignment->role_name === 'RECEPTIONIST' && $same($assignment->business_id, $booking->business_id) && $same($assignment->branch_id, $booking->branch_id) => 'RECEPTIONIST',
            default => null,
        };
        abort_unless($role, 404, 'Booking not found');

        return ['booking' => $booking, 'role' => $role];
    }

    public function present(object $deposit, array $refunds): array
    {
        $canPay = $deposit->status === 'AWAITING_PAYMENT' && strtotime($deposit->expires_at.' UTC') > time();
        $qr = null;
        if ($canPay && $deposit->environment === 'live' && $deposit->funding_source === DirectDeposit::FUNDING) {
            $qr = 'https://vietqr.app/img?'.http_build_query(['acc' => $deposit->account_number, 'bank' => $deposit->bank_code, 'amount' => (string) $deposit->amount_vnd, 'des' => $deposit->payment_code, 'holder' => $deposit->account_name, 'template' => 'compact', 'showinfo' => 'true']);
        }

        return ['fundingSource' => $deposit->funding_source, 'id' => (string) $deposit->id, 'bookingId' => (string) $deposit->booking_id, 'status' => $deposit->status, 'version' => (int) $deposit->version, 'environment' => $deposit->environment,
            'bankCode' => $deposit->bank_code, 'accountNumber' => $deposit->account_number, 'accountName' => $deposit->account_name, 'paymentCode' => $deposit->payment_code, 'totalVnd' => (string) $deposit->total_vnd, 'amountVnd' => (string) $deposit->amount_vnd,
            'remainingVnd' => (string) $deposit->remaining_vnd, 'rateBps' => 3000, 'cancellationHours' => (int) $deposit->cancellation_hours, 'expiresAt' => $this->iso($deposit->expires_at), 'paidAt' => $this->iso($deposit->paid_at),
            'storeReceivedAt' => $this->iso($deposit->store_received_at), 'qrUrl' => $qr, 'canPay' => $canPay,
            'refunds' => array_map(fn ($f) => ['id' => (string) $f->id, 'amountVnd' => (string) $f->amount_vnd, 'reason' => $f->reason, 'status' => $f->status, 'destination' => json_decode((string) $f->destination, true), 'reference' => $f->reference,
                'version' => (int) $f->version, 'reportedAt' => $this->iso($f->reported_at), 'confirmedAt' => $this->iso($f->confirmed_at)], $refunds)];
    }

    public function read(Request $r, int $bookingId): array
    {
        $access = $this->access($r, $bookingId);
        $deposit = DB::table('booking_deposits')->where('booking_id', $bookingId)->first();
        $refunds = $deposit ? DB::table('deposit_refunds')->where('deposit_id', $deposit->id)->orderBy('id')->get()->all() : [];
        $booking = \App\Models\Booking::find($bookingId);
        $payments = app(PaymentService::class);
        // The very question the action asks (PaymentService::storeBlocker), so the button and the backend can never disagree.
        $staff = $access['role'] !== 'CUSTOMER' && $deposit && $booking;

        return ['deposit' => $deposit ? $this->present($deposit, $refunds) : null, 'role' => $access['role'], 'canRecordStore' => $staff && $payments->canRecordStore($booking),
            'payment' => $staff ? $payments->paymentView($booking) : null, 'serverNow' => now('UTC')->toISOString()];
    }

    public function action(Request $r, int $bookingId, string $operation, array $raw): array
    {
        $version = self::version($raw['version'] ?? null);
        $access = $this->access($r, $bookingId);
        if ($operation === 'store-receipt') return $this->storeReceipt($r, $bookingId, $access, $version, $raw);

        return DB::transaction(function () use ($r, $bookingId, $operation, $raw, $version) {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking, 404, 'Booking not found');
            ['role' => $role] = $this->access($r, $bookingId);
            $deposit = DB::table('booking_deposits')->where('booking_id', $bookingId)->lockForUpdate()->first();
            abort_unless($deposit, 404, 'Deposit not found');
            $customerUser = (int) DB::table('customer_profiles')->where('id', $booking->customer_profile_id)->value('user_id');
            {
                abort_if($deposit->funding_source === 'SIMULATED_WALLET', 409, 'Simulated wallet refunds are processed by the booking lifecycle');
                $refund = DB::table('deposit_refunds')->where('id', (int) DirectDeposit::text((string) ($raw['refundId'] ?? ''), 20, '/^[1-9][0-9]*$/'))->where('deposit_id', $deposit->id)->lockForUpdate()->first();
                abort_unless($refund, 404, 'Refund not found');
                abort_unless((int) $refund->version === $version, 409, 'Refund changed; reload');
                $bump = ['version' => DB::raw('version + 1'), 'updated_at' => now()];
                if ($operation === 'refund-destination') {
                    abort_unless($role === 'CUSTOMER', 403, 'Customer required');
                    abort_unless($refund->status === 'PENDING', 409, 'Refund already reported');
                    DB::table('deposit_refunds')->where('id', $refund->id)->update(['destination' => json_encode(['bankCode' => DirectDeposit::text($raw['bankCode'] ?? null, 32, '/^[\p{L}\p{N} ._-]+$/u'),
                        'accountNumber' => DirectDeposit::text($raw['accountNumber'] ?? null, 64, '/^[A-Za-z0-9_-]{3,64}$/'), 'accountName' => DirectDeposit::text($raw['accountName'] ?? null, 120)], JSON_UNESCAPED_UNICODE)] + $bump);
                } elseif ($operation === 'refund-report') {
                    abort_unless($role === 'OWNER', 403, 'Business owner required');
                    abort_unless($refund->status === 'PENDING' && $refund->destination, 409, 'Customer refund destination is required');
                    DB::table('deposit_refunds')->where('id', $refund->id)->update(['status' => 'REPORTED', 'reference' => DirectDeposit::text($raw['reference'] ?? null, 200), 'reported_at' => now('UTC')->format('Y-m-d H:i:s')] + $bump);
                    app(BookingNotificationService::class)->record($customerUser, 'DEPOSIT_REFUND_REPORTED', 'Cơ sở đã báo chuyển trả cọc', 'Hãy kiểm tra tài khoản ngân hàng rồi xác nhận đã nhận đủ trong chi tiết lịch hẹn.', $bookingId, 'deposit-refund-reported:'.$refund->id, 'INFO');
                } elseif ($operation === 'refund-confirm') {
                    abort_unless($role === 'CUSTOMER', 403, 'Customer required');
                    abort_unless($refund->status === 'REPORTED', 409, 'Refund has not been reported');
                    DB::table('deposit_refunds')->where('id', $refund->id)->update(['status' => 'CONFIRMED', 'confirmed_at' => now('UTC')->format('Y-m-d H:i:s')] + $bump);
                    if ($deposit->status === 'REFUND_DUE' && ! DB::table('deposit_refunds')->where('deposit_id', $deposit->id)->where('status', '!=', 'CONFIRMED')->exists()) {
                        DB::table('booking_deposits')->where('id', $deposit->id)->update(['status' => 'REFUNDED'] + $bump);
                    }
                } else {
                    abort(400, 'Unknown payment action');
                }
            }
            $this->access($r, $bookingId);
            $this->audit($r, (int) $booking->business_id, (int) $booking->branch_id, 'DEPOSIT_'.strtoupper(str_replace('-', '_', $operation)), 'BookingDeposit', (int) $deposit->id);

            return ['success' => true];
        }, 3);
    }

    /**
     * "The remainder was paid at the salon". Authority is checked here (404 for strangers, 403 for the customer), then the
     * money itself is recorded by the one payment rule, under the business, booking, role and deposit locks, in that order.
     */
    private function storeReceipt(Request $r, int $bookingId, array $access, int $version, array $raw): array
    {
        abort_unless($access['role'] !== 'CUSTOMER', 403, 'Salon payment permission required');
        $reference = DirectDeposit::text($raw['reference'] ?? null, 200);
        $booking = $access['booking'];
        $operating = app(\App\Services\Beautybook\ApiScope::class)->requestForRoles($r, ['BUSINESS_OWNER', 'RECEPTIONIST'], $booking->business_id, $booking->branch_id);
        $result = app(PaymentService::class)->storeReceipt($operating, $bookingId, $version, ['reference' => $reference] + (isset($raw['method']) ? ['method' => $raw['method']] : []));
        if (! $result['replayed']) $this->audit($r, (int) $booking->business_id, (int) $booking->branch_id, 'DEPOSIT_STORE_RECEIPT', 'BookingDeposit', (int) DB::table('booking_deposits')->where('booking_id', $bookingId)->value('id'), ['transactionId' => (int) $result['transaction']->id]);

        return ['success' => true, 'replayed' => $result['replayed']];
    }

    // ---- the connection's webhook -------------------------------------------------------------------------------

    private function codes(string $content, ?string $code): array
    {
        preg_match_all('/\bG[MS][A-F0-9]{24}\b/', strtoupper($content), $m);
        $tokens = $m[0];
        if ($code !== null && preg_match('/^G[MS][A-F0-9]{24}$/', strtoupper($code))) $tokens[] = strtoupper($code);

        return array_values(array_unique($tokens));
    }

    /** Stores the signed receipt once (replays are no-ops), then applies it to at most one deposit. */
    public function webhook(Request $request, int $connectionId): array
    {
        $connection = DB::table('payment_connections')->where('id', $connectionId)->first();
        abort_unless($connection, 404, 'Connection not found');
        $config = ['environment' => $connection->environment, 'gateway' => $connection->bank_code, 'account_number' => $connection->account_number, 'secret' => DirectDeposit::open($connection->encrypted_secret, (int) $connection->id)];
        $receipt = $this->sepay->receiveForConnection($request, $config, $connectionId);
        $this->sepay->withoutDeadlock(fn () => DB::transaction(fn () => $this->apply((int) $receipt->id, $connection), 3));

        return ['success' => true];
    }

    private function apply(int $receiptId, object $connection): void
    {
        // Business first, before any read: the same order as every payment write (business, booking, deposit), and a view that starts after the lock.
        Business::whereKey($connection->business_id)->lockForUpdate()->first();
        $event = DB::table('sepay_webhook_events')->where('id', $receiptId)->first();
        $code = ($codes = $this->codes($event->content.' '.($event->payment_code ?? ''), null)) && count($codes) === 1 ? $codes[0] : null;
        if ($code === $connection->verification_code && $event->transfer_type === 'in') {
            Business::whereKey($connection->business_id)->lockForUpdate()->first();
            DB::table('payment_connections')->where('id', $connection->id)->where('status', 'SETUP')->update(['status' => 'READY', 'verified_at' => now('UTC')->format('Y-m-d H:i:s'), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('sepay_webhook_events')->where('id', $receiptId)->update(['processing' => 'VERIFIED', 'updated_at' => now()]);

            return;
        }
        if (! $code || $event->transfer_type !== 'in') return;
        $target = DB::table('booking_deposits')->where('payment_code', $code)->where('connection_id', $connection->id)->first(['id', 'booking_id']);
        if (! $target) return;
        $booking = DB::table('bookings')->where('id', $target->booking_id)->lockForUpdate()->first();
        $current = DB::table('sepay_webhook_events')->where('id', $receiptId)->lockForUpdate()->first();
        if ($current->processing !== 'UNMATCHED') return;
        $deposit = DB::table('booking_deposits')->where('id', $target->id)->lockForUpdate()->first();
        $branch = DB::table('branches')->where('id', $booking->branch_id)->first();
        $business = DB::table('businesses')->where('id', $booking->business_id)->first();
        $now = now('UTC');
        $zone = config('app.timezone');
        $problem = null;
        if ($deposit->status !== 'AWAITING_PAYMENT') {
            $problem = in_array($deposit->status, ['EXPIRED', 'VOIDED'], true) ? 'LATE_PAYMENT' : 'DUPLICATE_PAYMENT';
        } elseif ($booking->status !== 'PENDING' || ! $booking->pending_expires_at || strtotime($deposit->expires_at.' UTC') <= $now->getTimestamp()
            || strtotime($booking->pending_expires_at.' '.$zone) <= $now->getTimestamp() || strtotime($booking->appointment_start_at.' UTC') <= $now->getTimestamp()) {
            $problem = 'LATE_PAYMENT';
        } elseif (($branch->deleted_at ?? null) || ($business->deleted_at ?? null) || ! in_array($branch->status, ['APPROVED', 'ACTIVE'], true) || ! in_array($business->status, ['APPROVED', 'ACTIVE'], true)) {
            $problem = 'BOOKING_UNAVAILABLE';
        } elseif (($money = PaymentService::recordedMoney((int) $booking->id)) && ($money['recorded_cents'] > 0 || $money['pending_cents'] > 0)) {
            // The store already recorded money for this price (data from before the deposit gate): the transfer is owed back, not stacked on it.
            $problem = 'STORE_PAYMENT_RECORDED';
        } elseif ((int) $event->amount_vnd !== (int) $deposit->amount_vnd) {
            $problem = 'AMOUNT_MISMATCH';
        }
        $customerUser = (int) DB::table('customer_profiles')->where('id', $booking->customer_profile_id)->value('user_id');
        DB::table('sepay_webhook_events')->where('id', $receiptId)->update(['deposit_id' => $deposit->id, 'processing' => $problem ? 'REFUND_REQUIRED' : 'APPLIED', 'reviewed_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => now()]);
        if ($problem) {
            DB::table('deposit_refunds')->insert(['deposit_id' => $deposit->id, 'source' => DirectDeposit::FUNDING, 'event_id' => $receiptId, 'amount_vnd' => $event->amount_vnd, 'reason' => $problem, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);
            if (in_array($problem, ['LATE_PAYMENT', 'BOOKING_UNAVAILABLE'], true) && $deposit->status === 'AWAITING_PAYMENT') {
                DB::table('booking_deposits')->where('id', $deposit->id)->update(['status' => $problem === 'BOOKING_UNAVAILABLE' ? 'VOIDED' : 'EXPIRED', 'version' => $deposit->version + 1, 'updated_at' => now()]);
            }
            app(BookingNotificationService::class)->record($customerUser, 'DEPOSIT_REFUND_DUE', 'Khoản chuyển khoản cần hoàn lại', 'Cơ sở đã nhận một khoản chuyển cho lịch hẹn nhưng không thể ghi nhận làm cọc. Hãy khai báo tài khoản nhận hoàn trong chi tiết lịch hẹn.', (int) $booking->id, 'deposit-refund-due:'.$receiptId, 'INFO');
            $this->audit(null, (int) $business->id, (int) $booking->branch_id, 'DEPOSIT_REFUND_REQUIRED', 'BookingDeposit', (int) $deposit->id, ['eventId' => $receiptId, 'reason' => $problem, 'amountVnd' => (string) $event->amount_vnd]);

            return;
        }
        DB::table('booking_deposits')->where('id', $deposit->id)->update(['status' => 'PAID', 'paid_at' => $now->format('Y-m-d H:i:s'), 'version' => $deposit->version + 1, 'updated_at' => now()]);
        if ($deposit->confirmation_mode === 'AUTO') {
            CustomerBookingFinalizer::confirmByPolicy(\App\Models\Booking::findOrFail($booking->id), $customerUser, 'DEPOSIT_RECEIVED_AUTO_CONFIRMATION');
        } else {
            $hold = min($now->copy()->addMinutes((int) $deposit->pending_hold_minutes), CarbonImmutable::parse($booking->appointment_start_at, 'UTC'));
            DB::table('bookings')->where('id', $booking->id)->update(['pending_expires_at' => $hold->setTimezone($zone)->format('Y-m-d H:i:s'), 'updated_at' => now()]);
        }
        app(BookingNotificationService::class)->record($customerUser, 'DEPOSIT_PAID', 'Đã nhận tiền cọc', 'Cơ sở đã nhận cọc cho lịch hẹn '.$booking->booking_code.'.', (int) $booking->id, 'deposit-paid:'.$deposit->id, 'INFO');
        $this->audit(null, (int) $business->id, (int) $booking->branch_id, 'DEPOSIT_PAID', 'BookingDeposit', (int) $deposit->id, ['eventId' => $receiptId, 'amountVnd' => (string) $event->amount_vnd, 'environment' => $connection->environment]);
    }
}
