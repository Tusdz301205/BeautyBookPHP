<?php

namespace App\Services\Wallet;

/**
 * Who may use the private LIVE_LAB wallet, with which limits and against which receiving account
 * (live-wallet-policy.ts). Misconfiguration closes the lab (503); it never falls back to a default.
 */
class LiveWalletPolicy
{
    public const BANKS = ['acb', 'mbbank', 'tpbank', 'vpbank', 'vietinbank', 'sacombank', 'vietcombank'];

    public static function walletMode(): bool
    {
        return in_array((string) config('wallet.mode'), ['INTERNAL_WALLET', 'SIMULATED_WALLET'], true);
    }

    /** @return list<int> */
    private static function ids(string $name): array
    {
        $values = array_values(array_filter(array_map('trim', explode(',', (string) config('wallet.live_lab.'.$name)))));
        if (count($values) > 100 || array_filter($values, fn ($v) => ! preg_match('/^[1-9][0-9]{0,17}$/', $v))) return [];

        return array_values(array_unique(array_map('intval', $values)));
    }

    public static function liveLabUser(int $userId): bool
    {
        return in_array($userId, self::ids('user_ids'), true);
    }

    public static function scope(mixed $value): ?string
    {
        if ($value === null) return null;
        if (! in_array($value, ['DEMO', 'LIVE_LAB'], true)) abort(400, 'Invalid wallet scope');

        return $value;
    }

    private static function limit(string $name, int $fallback, int $maximum): int
    {
        $text = (string) config('wallet.live_lab.'.$name, $fallback);
        if (! preg_match('/^[1-9][0-9]*$/', $text) || strlen($text) > 12 || (int) $text > $maximum) abort(503, 'Invalid Live lab limits');

        return (int) $text;
    }

    /** @return array{minimum:int,maximum:int,total:int} */
    public static function limits(): array
    {
        $minimum = self::limit('min_topup_vnd', 1000, 1000000000);
        $maximum = self::limit('max_topup_vnd', 50000, 1000000000);
        $total = self::limit('max_total_vnd', 500000, 1000000000);
        if ($minimum > $maximum || $maximum > $total) abort(503, 'Invalid Live lab limits');

        return compact('minimum', 'maximum', 'total');
    }

    /**
     * Receiving account and webhook secret of the lab. `receiveOnly` is the webhook's view: it must authenticate
     * and store a receipt even when spending is switched off, so users/businesses/mode are not required.
     *
     * @return array{environment:string,secret:string,gateway:string,account_number:string,account_name:string}
     */
    public static function configuration(bool $receiveOnly = false): array
    {
        $c = config('wallet.live_lab');
        if (app()->environment('production') || ! $c['isolated']
            || (! $receiveOnly && (! self::walletMode() || ! $c['enabled'] || ! self::ids('user_ids') || ! self::ids('business_ids')))) {
            abort(503, 'Private SePay Live lab is disabled');
        }
        $secret = (string) $c['webhook_secret'];
        $gateway = trim((string) $c['bank_code']);
        $account = trim((string) $c['account_number']);
        if (strlen($secret) < 32 || strlen($secret) > 512 || $secret !== trim($secret) || ! preg_match('/^[\p{L}\p{N} ._-]{2,100}$/u', $gateway) || ! preg_match('/^[A-Za-z0-9_-]{3,64}$/', $account)) {
            abort(503, 'SePay webhook is not configured');
        }
        if (! in_array(strtolower($gateway), self::BANKS, true)) abort(503, 'This private lab supports primary accounts only; VA configuration is not implemented for this bank');
        $name = trim((string) $c['account_name']);
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f]/', $name)) abort(503, 'Live lab account holder is missing');
        self::limits();

        return ['environment' => 'live', 'secret' => $secret, 'gateway' => $gateway, 'account_number' => $account, 'account_name' => $name];
    }

    public static function requireUser(int $userId): array
    {
        if (! self::liveLabUser($userId)) abort(403, 'This account is not authorized for private Live testing');

        return self::configuration();
    }

    public static function requireSpend(int $userId, int $businessId): void
    {
        self::requireUser($userId);
        if (! in_array($businessId, self::ids('business_ids'), true)) abort(403, 'This business is outside the Live testing scope');
    }

    public static function available(int $userId): bool
    {
        try {
            self::requireUser($userId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{bankCode:string,accountNumber:string,accountName:string,environment:string} */
    public static function receiverSnapshot(array $config): array
    {
        return ['bankCode' => $config['gateway'], 'accountNumber' => $config['account_number'], 'accountName' => $config['account_name'], 'environment' => 'live'];
    }

    /** The QR is shown only for a pending, unexpired invoice whose receiver still matches the current configuration. */
    public static function qr(object $invoice, int $userId): ?string
    {
        if ($invoice->status !== 'PENDING' || strtotime($invoice->expires_at.' UTC') <= time() || ! self::available($userId)) return null;
        $snapshot = json_decode((string) $invoice->receiver_snapshot, true);
        $current = self::receiverSnapshot(self::configuration());
        if (! is_array($snapshot) || array_filter($current, fn ($v, $k) => ($snapshot[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH)) return null;
        $memo = (strtolower($snapshot['bankCode']) === 'vietinbank' ? 'SEVQR ' : '').$invoice->code;

        return 'https://vietqr.app/img?'.http_build_query(['bank' => $snapshot['bankCode'], 'acc' => $snapshot['accountNumber'], 'amount' => (string) $invoice->amount_vnd,
            'des' => $memo, 'holder' => $snapshot['accountName'], 'template' => 'compact', 'showinfo' => 'true']);
    }
}
