<?php

namespace App\Services\Payments;

use App\Services\Wallet\LiveWalletPolicy;
use App\Services\Wallet\WalletCore;
use Illuminate\Support\Facades\DB;

/**
 * Direct business deposits (deposit-core.ts): the customer pays 30% of the booking straight into the business's own
 * bank account and a signed SePay receipt for that connection confirms it. The platform holds no money in this mode.
 * Wallet mode and direct mode are exclusive: wallet mode wins when both are configured.
 */
class DirectDeposit
{
    public const BANKS = ['ACB', 'MBBank', 'TPBank', 'VPBank', 'VietinBank', 'Sacombank'];
    public const FUNDING = 'DIRECT_SEPAY';

    public static function enabled(): bool
    {
        return (bool) config('wallet.direct.enabled');
    }

    /** True when the server takes real money (APP_ENV=production). */
    public static function production(): bool
    {
        return (bool) config('wallet.direct.production');
    }

    /** SePay Test mode connections: always outside production, inside it only by explicit setting. */
    public static function allowTest(): bool
    {
        return ! self::production() || (bool) config('wallet.direct.allow_test');
    }

    /** The exact address to paste into SePay for a connection. */
    public static function webhookUrl(int $connectionId): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/payments/sepay/connections/'.$connectionId.'/webhook';
    }

    /** Validated free text of the payment forms (paymentText): trimmed, NFC, no control characters. */
    public static function text(mixed $value, int $max, ?string $pattern = null): string
    {
        if (! is_string($value)) abort(422, 'Invalid payment information');
        $s = trim(\Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value);
        if ($s === '' || mb_strlen($s) > $max || preg_match('/[\x00-\x1f]/', $s) || ($pattern && ! preg_match($pattern, $s))) abort(422, 'Invalid payment information');

        return $s;
    }

    private static function key(): string
    {
        $hex = (string) config('wallet.direct.encryption_key');
        if (! preg_match('/^[a-f0-9]{64}$/i', $hex)) abort(503, 'Payment encryption is not configured');

        return hex2bin($hex);
    }

    /** AES-256-GCM, bound to the connection id so a sealed secret cannot be moved to another row. */
    public static function seal(string $secret, int $id): string
    {
        $iv = random_bytes(12);
        $body = openssl_encrypt($secret, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, (string) $id);
        if ($body === false) abort(503, 'Payment credentials are unavailable');
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return implode('.', [$b64($iv), $b64($tag), $b64($body)]);
    }

    public static function open(string $sealed, int $id): string
    {
        $parts = explode('.', $sealed);
        $raw = fn (string $v) => base64_decode(strtr($v, '-_', '+/'), true);
        if (count($parts) !== 3 || in_array(false, array_map($raw, $parts), true)) abort(503, 'Payment credentials are unavailable');
        [$iv, $tag, $body] = array_map($raw, $parts);
        $plain = openssl_decrypt($body, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, (string) $id);
        if ($plain === false) abort(503, 'Payment credentials are unavailable');

        return $plain;
    }

    /** @return array{totalVnd:int,depositVnd:int,remainingVnd:int} total rounded to whole VND, deposit rounded up */
    public static function amounts(int|float|string $total): array
    {
        if (! is_numeric($total) || $total < 0 || $total > 999999999999) abort(400, 'Invalid payment amount');
        $payable = (int) round((float) $total, 0, PHP_ROUND_HALF_UP);
        $deposit = intdiv($payable * 3 + 9, 10);

        return ['totalVnd' => $payable, 'depositVnd' => $deposit, 'remainingVnd' => $payable - $deposit];
    }

    public static function activeConnection(int $businessId): ?object
    {
        return DB::table('payment_connections')->where('business_id', $businessId)->where('enabled', 1)->where('status', 'READY')
            ->whereNotNull('verified_at')->whereIn('environment', self::allowTest() ? ['live', 'test'] : ['live'])->first();
    }

    /** Read-only booking readiness. Missing bank setup must never turn a required deposit into a free booking. */
    public static function readiness(int $businessId, int|float|string $total): array
    {
        $required = ! LiveWalletPolicy::walletMode() && self::enabled() && self::amounts($total)['depositVnd'] > 0;
        $ready = ! $required || self::activeConnection($businessId) !== null;

        return ['direct' => ! LiveWalletPolicy::walletMode() && self::enabled(), 'required' => $required, 'ok' => $ready,
            'reasonCode' => $ready ? null : 'DIRECT_DEPOSIT_UNAVAILABLE',
            'reason' => $ready ? null : 'Cơ sở chưa có tài khoản nhận cọc đã xác minh và đang bật. Chưa thể đặt lịch trực tuyến có phí.'];
    }

    /**
     * The terms a customer must accept before booking, or null when no direct deposit applies (wallet mode, feature
     * off, no enabled and verified account, or a zero deposit). Readiness distinguishes missing setup from no deposit;
     * createFor enforces it. The quote covers the account, so a changed account asks again.
     */
    public static function terms(int $businessId, int $branchId, int|float|string $total, int $cancellationHours): ?array
    {
        if (LiveWalletPolicy::walletMode() || ! self::enabled()) return null;
        $account = self::activeConnection($businessId);
        $amounts = self::amounts($total);
        if (! $account || $amounts['depositVnd'] === 0) return null;
        $terms = ['fundingSource' => self::FUNDING] + $amounts + ['rateBps' => 3000, 'cancellationHours' => $cancellationHours];

        return $terms + ['environment' => $account->environment, 'bankCode' => $account->bank_code, 'accountName' => $account->account_name,
            'quote' => WalletCore::hash(['version' => 2, 'branchId' => $branchId, 'connectionId' => (int) $account->id,
                'connectionVersion' => (int) $account->version, 'environment' => $account->environment, 'bankCode' => $account->bank_code,
                'accountNumber' => $account->account_number, 'accountName' => $account->account_name] + $amounts + ['rateBps' => 3000, 'cancellationHours' => $cancellationHours]), 'connectionId' => (int) $account->id];
    }

    /**
     * Creates the awaiting-payment deposit inside the booking's transaction. The customer must have accepted the current
     * quote; the booking stays PENDING until the receipt arrives, and its hold ends no later than the deposit's.
     *
     * @param array{cancellationHours:int,pendingHoldMinutes:int,confirmationMode:string} $rules
     */
    public static function createFor(object $booking, object $branch, int|float|string $total, \Carbon\CarbonInterface $startAt, ?string $acceptedQuote, array $rules): ?object
    {
        $readiness = self::readiness((int) $branch->business_id, $total);
        abort_unless($readiness['ok'], 409, $readiness['reason']);
        $terms = self::terms((int) $branch->business_id, (int) $branch->id, $total, $rules['cancellationHours']);
        if (! $terms) return null;
        if ($acceptedQuote !== $terms['quote']) abort(409, 'Review and accept the current deposit before booking');
        $account = DB::table('payment_connections')->where('id', $terms['connectionId'])->first();
        $now = now('UTC');
        $id = DB::table('booking_deposits')->insertGetId(['booking_id' => $booking->id, 'wallet_id' => null, 'funding_source' => self::FUNDING, 'connection_id' => $account->id,
            'payment_code' => 'GM'.strtoupper(bin2hex(random_bytes(12))), 'total_vnd' => $terms['totalVnd'], 'amount_vnd' => $terms['depositVnd'], 'remaining_vnd' => $terms['remainingVnd'], 'rate_bps' => 3000,
            'status' => 'AWAITING_PAYMENT', 'environment' => $account->environment, 'bank_code' => $account->bank_code, 'account_number' => $account->account_number, 'account_name' => $account->account_name,
            'confirmation_mode' => $rules['confirmationMode'] === 'AUTO' ? 'AUTO' : 'MANUAL', 'pending_hold_minutes' => $rules['pendingHoldMinutes'], 'cancellation_hours' => $rules['cancellationHours'],
            'expires_at' => min($now->copy()->addMinutes($rules['pendingHoldMinutes']), $startAt)->utc()->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('booking_deposits')->find($id);
    }

    /**
     * Applies a booking status to its direct deposit (depositTransition). No payment is ever inferred from a status: a
     * booking cannot be confirmed, checked in, completed or marked no-show without a received deposit. Caller holds the booking lock.
     *
     * @param array{customerCancelled?:bool,requestedAt?:?\Carbon\CarbonInterface,now?:?\Carbon\CarbonInterface,actorId?:?int} $options
     */
    public static function resolve(object $deposit, string $target, array $options = []): void
    {
        if ($target === 'NO_SHOW' && $deposit->status === 'FORFEITED') return;
        if (in_array($target, ['CONFIRMED', 'CHECKED_IN', 'COMPLETED', 'NO_SHOW'], true) && ! in_array($deposit->status, ['PAID', 'APPLIED'], true)) abort(409, 'Khoản cọc của lịch hẹn chưa được nhận.');
        if ($target === 'COMPLETED') {
            DB::table('booking_deposits')->where('id', $deposit->id)->update(['status' => 'APPLIED', 'version' => $deposit->version + 1, 'updated_at' => now()]);

            return;
        }
        if (! in_array($target, ['CANCELLED', 'REJECTED', 'EXPIRED', 'NO_SHOW'], true)) return;
        if ($deposit->store_received_at) abort(409, 'Resolve the recorded store payment before cancelling');
        if ($deposit->status === 'AWAITING_PAYMENT') {
            DB::table('booking_deposits')->where('id', $deposit->id)->update(['status' => $target === 'EXPIRED' ? 'EXPIRED' : 'VOIDED', 'version' => $deposit->version + 1, 'updated_at' => now()]);

            return;
        }
        if ($deposit->status !== 'PAID') return;
        $now = $options['now'] ?? now('UTC');
        $startAt = \Carbon\CarbonImmutable::parse(DB::table('bookings')->where('id', $deposit->booking_id)->value('appointment_start_at'), 'UTC');
        $late = $target === 'NO_SHOW' || (! empty($options['customerCancelled']) && ($options['requestedAt'] ?? $now)->greaterThan($startAt->subHours((int) $deposit->cancellation_hours)));
        if ($late) {
            DB::table('booking_deposits')->where('id', $deposit->id)->update(['status' => 'FORFEITED', 'version' => $deposit->version + 1, 'updated_at' => now()]);

            return;
        }
        $receipt = DB::table('sepay_webhook_events')->where('deposit_id', $deposit->id)->where('processing', 'APPLIED')->first();
        if (! $receipt) abort(409, 'Deposit receipt is unavailable');
        DB::table('deposit_refunds')->insertOrIgnore(['deposit_id' => $deposit->id, 'source' => self::FUNDING, 'event_id' => $receipt->id, 'amount_vnd' => $receipt->amount_vnd, 'reason' => 'BOOKING_'.$target, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('booking_deposits')->where('id', $deposit->id)->update(['status' => 'REFUND_DUE', 'version' => $deposit->version + 1, 'updated_at' => now()]);
        $userId = (int) DB::table('bookings as b')->join('customer_profiles as p', 'p.id', '=', 'b.customer_profile_id')->where('b.id', $deposit->booking_id)->value('p.user_id');
        app(\App\Services\Booking\BookingNotificationService::class)->record($userId, 'DEPOSIT_REFUND_DUE', 'Cọc sẽ được hoàn lại', 'Hãy khai báo tài khoản nhận hoàn trong chi tiết lịch hẹn để cơ sở chuyển trả.', (int) $deposit->booking_id, 'deposit-refund-due:'.$deposit->id, 'INFO');
    }
}
