<?php

namespace App\Services\Finance;

use App\Models\SepayWebhookEvent;
use App\Services\Wallet\LiveWalletInbox;
use App\Services\Wallet\LiveWalletPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SePay webhook contract carried over from the API package (src/payments/sepay-webhook.*.ts, live-wallet-inbox.ts):
 * HMAC-SHA256 over "{timestamp}.{rawBody}" and a durable inbox keyed by (environment, provider id).
 *  - POST payments/sepay/webhook: the ordinary endpoint stores the receipt only ("never a wallet credit").
 *  - POST payments/sepay/wallet-live-lab/webhook: the private Live-lab channel; the same receipt is matched to a
 *    wallet top-up invoice and credits the wallet once, or is sent to manual review with a reason.
 */
class SepayWebhookService
{
    public const BODY_LIMIT = 32768;

    /** @return array{environment:string,secret:string,gateway:string,account_number:string} */
    public function configuration(): array
    {
        $c = config('services.sepay');
        $secret = (string) ($c['secret'] ?? '');
        $gateway = trim((string) ($c['gateway'] ?? ''));
        $account = trim((string) ($c['account_number'] ?? ''));
        if (! filter_var($c['enabled'] ?? false, FILTER_VALIDATE_BOOL) || ! in_array($c['environment'] ?? null, ['test', 'live'], true)
            || strlen($secret) < 32 || strlen($secret) > 512 || $secret !== trim($secret)
            || ! preg_match('/^[\p{L}\p{N} ._-]{2,100}$/u', $gateway) || ! preg_match('/^[A-Za-z0-9_-]{3,64}$/', $account)) {
            abort(503, 'SePay webhook is not configured');
        }

        return ['environment' => $c['environment'], 'secret' => $secret, 'gateway' => $gateway, 'account_number' => $account];
    }

    public function verifySignature(Request $request, array $config, ?int $now = null): string
    {
        $type = (string) $request->header('Content-Type', '');
        if (! preg_match('/^application\/json(?:\s*;|\s*$)/i', $type) || ($request->header('Content-Encoding') && $request->header('Content-Encoding') !== 'identity')) abort(415, 'SePay requires uncompressed JSON');
        $raw = $request->getContent();
        if (strlen($raw) > self::BODY_LIMIT) abort(413);
        $signature = $request->header('X-Sepay-Signature');
        $timestamp = $request->header('X-Sepay-Timestamp');
        if (! is_string($signature) || ! preg_match('/^sha256=[a-f0-9]{64}$/i', $signature)
            || ! is_string($timestamp) || ! preg_match('/^[1-9][0-9]{9,10}$/', $timestamp)
            || abs(($now ?? time()) - (int) $timestamp) > 300) abort(401, 'Invalid SePay signature');
        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $config['secret']);
        if (! hash_equals($expected, strtolower(substr($signature, 7)))) abort(401, 'Invalid SePay signature');

        return $raw;
    }

    private function bad(): never
    {
        abort(400, 'Invalid SePay transaction');
    }

    private function text(array $body, string $key, int $max, bool $required = false): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            if ($required) $this->bad();

            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $max || str_contains($value, "\0") || ($required && trim($value) === '')) $this->bad();

        return $value;
    }

    private function integer(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (! is_int($value) || $value < 1) $this->bad();

        return $value;
    }

    /** Parse a signed payload into an inbox row. Extra provider fields are tolerated; no balance is trusted. */
    public function parse(string $raw, array $config, bool $allowReceiverMismatch = false): array
    {
        $body = json_decode($raw, true);
        if (! is_array($body) || array_is_list($body) || json_last_error() !== JSON_ERROR_NONE) $this->bad();
        $id = $this->integer($body, 'id');
        $gateway = trim($this->text($body, 'gateway', 100, true));
        $account = $this->text($body, 'accountNumber', 64, true);
        if (! $allowReceiverMismatch && (strtolower($gateway) !== strtolower($config['gateway']) || $account !== $config['account_number'])) abort(400, 'SePay receiving account does not match');
        $date = $this->text($body, 'transactionDate', 19, true);
        $at = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date) ? CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date, '+07:00') : null;
        if (! $at || $at->format('Y-m-d H:i:s') !== $date) $this->bad();
        $type = $body['transferType'] ?? null;
        if ($type !== 'in' && $type !== 'out') $this->bad();
        $amount = $this->integer($body, 'transferAmount');
        $content = $this->text($body, 'content', 2000, true);
        $code = $this->text($body, 'code', 128);
        $reference = $this->text($body, 'referenceCode', 255);
        $sub = $this->text($body, 'subAccount', 128);
        $description = $this->text($body, 'description', 4000);
        $fingerprint = hash('sha256', json_encode(['id' => (string) $id, 'gateway' => strtolower($gateway), 'accountNumber' => $account, 'transactionAt' => $at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'transferType' => $type, 'amountVnd' => (string) $amount, 'content' => $content, 'paymentCode' => $code, 'referenceCode' => $reference, 'subAccount' => $sub], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $now = now('UTC')->format('Y-m-d H:i:s');

        return ['environment' => $config['environment'], 'provider_transaction_id' => $id, 'gateway' => $gateway, 'account_number' => $account, 'sub_account' => $sub,
            'transaction_at' => $at->utc()->format('Y-m-d H:i:s'), 'transfer_type' => $type, 'amount_vnd' => $amount, 'content' => $content, 'payment_code' => $code,
            'reference_code' => $reference, 'description' => $description, 'fingerprint' => $fingerprint, 'payload_sha256' => hash('sha256', $raw),
            'status' => $type === 'in' ? 'REVIEW_REQUIRED' : 'IGNORED_OUTGOING', 'processing' => 'UNMATCHED', 'channel' => 'LEGACY', 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now];
    }

    /** Stores the receipt once. Returns the stored row after checking the identity fingerprint. */
    private function store(array $row, string $storageMessage): SepayWebhookEvent
    {
        try {
            // INSERT IGNORE: a concurrent or repeated delivery can never overwrite the first receipt.
            $stored = $this->withoutDeadlock(function () use ($row) {
                DB::table('sepay_webhook_events')->insertOrIgnore($row);

                return SepayWebhookEvent::whereNull('connection_id')->where('environment', $row['environment'])->where('provider_transaction_id', $row['provider_transaction_id'])->first();
            });
        } catch (\Throwable) {
            abort(503, $storageMessage.' storage is unavailable');
        }
        if (! $stored) abort(503, $storageMessage.' was not persisted');
        if ($stored->fingerprint !== $row['fingerprint']) abort(409, 'SePay transaction identity conflicts with an existing receipt');

        return $stored;
    }

    /** Ordinary endpoint: durable inbox only, never a credit. No 200 unless the receipt is persisted. */
    public function receive(Request $request): array
    {
        $config = $this->configuration();
        $this->store($this->parse($this->verifySignature($request, $config), $config), 'SePay receipt');

        return ['success' => true];
    }

    /** Private Live-lab channel: the receipt is stored for the wallet inbox and processed right away. */
    public function receiveLiveLab(Request $request): array
    {
        $config = LiveWalletPolicy::configuration(true);
        $row = $this->parse($this->verifySignature($request, $config), $config, true);
        $row['channel'] = 'WALLET_LIVE_LAB';
        $row['processing'] = 'RETRY_PENDING';
        $stored = $this->store($row, 'Live receipt');
        if ($stored->channel !== 'WALLET_LIVE_LAB') abort(409, 'Receipt belongs to a different payment inbox');
        try {
            $this->withoutDeadlock(fn () => DB::transaction(fn () => LiveWalletInbox::process($stored->id), 3));
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'SePay receipt will be retried');
        }

        return ['success' => true];
    }

    /** Parallel deliveries of one receipt can deadlock on the unique key; every step is idempotent, so retry with jitter. */
    public function withoutDeadlock(callable $work, int $attempts = 8): mixed
    {
        for ($attempt = 1;; $attempt++) {
            try {
                return $work();
            } catch (\Illuminate\Database\QueryException $e) {
                $transient = in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true);
                if (! $transient || $attempt >= $attempts) throw $e;
                usleep(random_int(5, 40) * 1000 * $attempt);
            }
        }
    }

    /**
     * A direct-deposit connection's receipt (direct-deposit.service.ts webhook): verified with that connection's own secret and
     * account, stored once per (connection, environment, bank transaction), and returned for the caller to apply.
     */
    public function receiveForConnection(Request $request, array $config, int $connectionId): object
    {
        $row = $this->parse($this->verifySignature($request, $config), $config);
        $row['channel'] = 'DIRECT_DEPOSIT';
        $row['connection_id'] = $connectionId;
        try {
            $stored = $this->withoutDeadlock(function () use ($row, $connectionId) {
                DB::table('sepay_webhook_events')->insertOrIgnore($row);

                return DB::table('sepay_webhook_events')->where('connection_id', $connectionId)->where('environment', $row['environment'])->where('provider_transaction_id', $row['provider_transaction_id'])->first();
            });
        } catch (\Throwable) {
            abort(503, 'Deposit receipt storage is unavailable');
        }
        if (! $stored) abort(503, 'Deposit receipt was not persisted');
        if ($stored->fingerprint !== $row['fingerprint']) abort(409, 'SePay transaction identity conflict');

        return $stored;
    }

    /** Called by maintenance: Live-lab receipts whose first processing attempt failed are retried. Returns the failures. */
    public function recover(int $limit = 50): int
    {
        try {
            LiveWalletPolicy::configuration();
        } catch (\Throwable) {
            return 0;
        }
        $failed = 0;
        foreach (SepayWebhookEvent::where('channel', 'WALLET_LIVE_LAB')->where('processing', 'RETRY_PENDING')->orderBy('received_at')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            try {
                $this->withoutDeadlock(fn () => DB::transaction(fn () => LiveWalletInbox::process($id), 3));
            } catch (\Throwable $e) {
                report($e);
                $failed++;
            }
        }

        return $failed;
    }
}
