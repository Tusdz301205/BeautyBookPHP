<?php

namespace App\Services\Wallet;

use App\Services\Booking\BookingNotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Top-up completion and the LIVE_LAB receipt processor (wallet-topup-core.ts, live-wallet-inbox.ts).
 * A receipt credits a wallet only when its code, receiving account, amount, time window, user authority and
 * limits all match one invoice; everything else goes to MANUAL_REVIEW with a reason and credits nothing.
 */
class LiveWalletInbox
{
    private static function audit(?int $actorId, string $action, string $entityType, int $entityId, array $metadata): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actorId, 'business_id' => null, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => (string) $entityId, 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }

    private static function notify(int $userId, string $type, string $title, string $body, string $key): void
    {
        app(BookingNotificationService::class)->record($userId, $type, $title, $body, null, $key, 'INFO', ['simulation' => true]);
    }

    public static function expire(int $topUpId): void
    {
        $ref = DB::table('wallet_topups as t')->join('wallets as w', 'w.id', '=', 't.wallet_id')->join('customer_profiles as p', 'p.id', '=', 'w.customer_profile_id')->where('t.id', $topUpId)->first(['t.id', 'p.user_id']);
        $changed = DB::table('wallet_topups')->where('id', $topUpId)->where('provider', 'SEPAY_LIVE')->where('status', 'PENDING')->where('expires_at', '<=', now('UTC'))->update(['status' => 'EXPIRED', 'updated_at' => now()]);
        if ($changed) {
            self::notify((int) $ref->user_id, 'WALLET_TOPUP_EXPIRED', 'Yêu cầu nạp ví đã hết hạn', 'Mã nạp ví đã hết hạn. Tạo yêu cầu mới nếu bạn chưa chuyển khoản.', 'live-expired:'.$topUpId);
            self::audit(null, 'LIVE_LAB_TOPUP_EXPIRED', 'WalletTopUp', $topUpId, ['performedBy' => 'SYSTEM']);
        }
    }

    /** Maintenance: expires due Live-lab invoices under the same lock order as top-up creation. */
    public static function expireDue(int $limit = 50): int
    {
        $count = 0;
        $due = DB::table('wallet_topups as t')->join('wallets as w', 'w.id', '=', 't.wallet_id')->join('customer_profiles as p', 'p.id', '=', 'w.customer_profile_id')
            ->where('t.provider', 'SEPAY_LIVE')->where('t.status', 'PENDING')->where('t.expires_at', '<=', now('UTC'))->orderBy('t.expires_at')->orderBy('t.id')->limit($limit)->get(['t.id', 'w.customer_profile_id', 'p.user_id']);
        foreach ($due as $row) {
            DB::transaction(function () use ($row) {
                DB::table('users')->where('id', $row->user_id)->lockForUpdate()->first();
                DB::table('customer_profiles')->where('id', $row->customer_profile_id)->lockForUpdate()->first();
                self::expire((int) $row->id);
            }, 3);
            $count++;
        }

        return $count;
    }

    /** @param array{provider:string,code:string,amount:int,identity:string,success:bool,bankEventId?:int} $proof */
    public static function complete(int $topUpId, array $proof, ?int $actorId = null): bool
    {
        $ref = DB::table('wallet_topups as t')->join('wallets as w', 'w.id', '=', 't.wallet_id')->where('t.id', $topUpId)->first(['t.*', 'w.customer_profile_id', 'w.scope as wallet_scope']);
        if (! $ref) abort(404, 'Top-up not found');
        // Retired providers can be read and reconciled as history, never credited again.
        if ($ref->provider !== 'SEPAY_LIVE' || $ref->wallet_scope !== 'LIVE_LAB' || $proof['provider'] !== 'SEPAY_LIVE') return false;
        if ($proof['success'] && empty($proof['bankEventId'])) return false;
        DB::table('customer_profiles')->where('id', $ref->customer_profile_id)->lockForUpdate()->first();
        $wallet = WalletCore::lock((int) $ref->customer_profile_id, 'LIVE_LAB');
        $row = DB::table('wallet_topups')->where('id', $topUpId)->lockForUpdate()->first();
        if ($row->provider !== $proof['provider'] || $row->code !== $proof['code'] || (int) $row->amount_vnd !== $proof['amount']) return false;
        if ($row->status !== 'PENDING') return $row->status === 'SUCCESS' && $row->receipt_identity === $proof['identity'];
        $now = now('UTC');
        if (strtotime($row->expires_at.' UTC') <= $now->getTimestamp()) {
            DB::table('wallet_topups')->where('id', $topUpId)->update(['status' => 'EXPIRED', 'updated_at' => now()]);

            return false;
        }
        if (! $proof['success']) {
            DB::table('wallet_topups')->where('id', $topUpId)->update(['status' => 'FAILED', 'completed_at' => $now->format('Y-m-d H:i:s'), 'receipt_identity' => $proof['identity'], 'updated_at' => now()]);

            return false;
        }
        WalletCore::post(['operationKey' => 'topup:'.$topUpId, 'kind' => 'TOPUP', 'walletId' => (int) $wallet->id, 'amount' => (int) $row->amount_vnd, 'availableDelta' => (int) $row->amount_vnd, 'actorId' => $actorId, 'reason' => 'SEPAY_LIVE_TOPUP']);
        DB::table('wallet_topups')->where('id', $topUpId)->update(['status' => 'SUCCESS', 'completed_at' => $now->format('Y-m-d H:i:s'), 'receipt_identity' => $proof['identity'], 'bank_event_id' => $proof['bankEventId'], 'updated_at' => now()]);
        $userId = (int) DB::table('customer_profiles')->where('id', $ref->customer_profile_id)->value('user_id');
        self::notify($userId, 'WALLET_TOPUP_SUCCESS', 'Nạp ví thành công', 'Ví đã nhận '.number_format((int) $row->amount_vnd, 0, ',', '.').'₫.', 'wallet-topup:'.$topUpId);
        self::audit($actorId, 'LIVE_LAB_WALLET_TOPUP', 'WalletTopUp', $topUpId, ['amountVnd' => (string) $row->amount_vnd, 'provider' => $proof['provider'], 'simulation' => true]);

        return true;
    }

    /** Runs inside the caller's transaction. Returns true when the receipt is (or already was) applied as a top-up. */
    public static function process(int $eventId): bool
    {
        $event = DB::table('sepay_webhook_events')->where('id', $eventId)->first();
        if ($event->channel !== 'WALLET_LIVE_LAB' || $event->environment !== 'live') return false;
        if ($event->processing === 'TOPUP_APPLIED') return true;
        $review = function (string $reason, ?int $topUpId = null) use ($eventId): bool {
            $changed = DB::table('sepay_webhook_events')->where('id', $eventId)->where('processing', '!=', 'TOPUP_APPLIED')
                ->where(fn ($q) => $q->where('processing', '!=', 'MANUAL_REVIEW')->orWhereNull('review_reason')->orWhere('review_reason', '!=', $reason))
                ->update(['processing' => 'MANUAL_REVIEW', 'review_reason' => $reason, 'reviewed_at' => now('UTC'), 'updated_at' => now()] + ($topUpId ? ['top_up_id' => $topUpId] : []));
            if ($changed) self::audit(null, 'LIVE_LAB_RECEIPT_REVIEW', 'SepayWebhookEvent', $eventId, ['reason' => $reason, 'performedBy' => 'SYSTEM']);
            if ($topUpId && $changed) {
                $userId = (int) DB::table('wallet_topups as t')->join('wallets as w', 'w.id', '=', 't.wallet_id')->join('customer_profiles as p', 'p.id', '=', 'w.customer_profile_id')->where('t.id', $topUpId)->value('p.user_id');
                self::notify($userId, 'WALLET_TOPUP_REVIEW', 'Khoản nạp ví đang được kiểm tra', 'Ngân hàng đã báo có nhưng khoản nạp chưa khớp yêu cầu. Quản trị viên sẽ kiểm tra giao dịch.', 'live-review:'.$eventId);
            }

            return false;
        };
        try {
            $config = LiveWalletPolicy::configuration();
        } catch (\Throwable) {
            return $review('LIVE_LAB_DISABLED');
        }
        if ($event->transfer_type !== 'in') return $review('OUTGOING_TRANSFER');
        if (strtolower($event->gateway) !== strtolower($config['gateway']) || $event->account_number !== $config['account_number'] || $event->sub_account) return $review('RECEIVING_ACCOUNT_MISMATCH');
        $found = preg_match_all('/\bWL[A-F0-9]{24}\b/', strtoupper($event->content.' '.($event->payment_code ?? '')), $m);
        $codes = $found ? array_values(array_unique($m[0])) : [];
        if (count($codes) !== 1) return $review('MISSING_OR_AMBIGUOUS_CODE');
        $invoice = DB::table('wallet_topups as t')->join('wallets as w', 'w.id', '=', 't.wallet_id')->join('customer_profiles as p', 'p.id', '=', 'w.customer_profile_id')->where('t.code', $codes[0])
            ->first(['t.*', 'w.scope as wallet_scope', 'w.customer_profile_id', 'p.user_id']);
        if (! $invoice || $invoice->provider !== 'SEPAY_LIVE' || $invoice->wallet_scope !== 'LIVE_LAB') return $review('INVOICE_NOT_FOUND');
        $userId = (int) $invoice->user_id;
        // user -> customer profile -> wallet -> invoice -> receipt: the same order as create and booking.
        DB::table('users')->where('id', $userId)->lockForUpdate()->first();
        DB::table('customer_profiles')->where('id', $invoice->customer_profile_id)->lockForUpdate()->first();
        WalletCore::lock((int) $invoice->customer_profile_id, 'LIVE_LAB');
        $current = DB::table('wallet_topups')->where('id', $invoice->id)->lockForUpdate()->first();
        $receipt = DB::table('sepay_webhook_events')->where('id', $eventId)->lockForUpdate()->first();
        if ($receipt->processing === 'TOPUP_APPLIED') return true;
        $snapshot = json_decode((string) $current->receiver_snapshot, true);
        $user = DB::table('users')->where('id', $userId)->first(['is_active', 'account_kind']);
        if (! LiveWalletPolicy::liveLabUser($userId) || ! $user || ! $user->is_active || $user->account_kind !== 'CUSTOMER') return $review('TEST_ACCOUNT_NOT_AUTHORIZED', (int) $invoice->id);
        try {
            $config = LiveWalletPolicy::configuration();
        } catch (\Throwable) {
            return $review('LIVE_LAB_DISABLED', (int) $invoice->id);
        }
        if ($event->account_number !== $config['account_number'] || strtolower($event->gateway) !== strtolower($config['gateway'])) return $review('RECEIVING_ACCOUNT_MISMATCH', (int) $invoice->id);
        if (! is_array($snapshot) || ($snapshot['environment'] ?? null) !== 'live' || strtolower((string) $snapshot['bankCode']) !== strtolower($event->gateway) || ($snapshot['accountNumber'] ?? null) !== $event->account_number) return $review('INVOICE_RECEIVER_MISMATCH', (int) $invoice->id);
        if ((int) $current->amount_vnd !== (int) $event->amount_vnd) return $review((int) $event->amount_vnd < (int) $current->amount_vnd ? 'UNDERPAID' : 'OVERPAID', (int) $invoice->id);
        if ($current->status === 'SUCCESS') return $review('ADDITIONAL_BANK_PAYMENT', (int) $invoice->id);
        $transactionAt = strtotime($event->transaction_at.' UTC');
        $expiresAt = strtotime($current->expires_at.' UTC');
        $createdAt = strtotime($current->created_at.' '.config('app.timezone'));
        if ($current->status !== 'PENDING' || $expiresAt <= time() || $transactionAt > $expiresAt) return $review('INVOICE_EXPIRED_OR_CLOSED', (int) $invoice->id);
        if ($transactionAt < $createdAt - 60 || $transactionAt > time() + 300) return $review('BANK_TRANSACTION_TIME_MISMATCH', (int) $invoice->id);
        $limits = LiveWalletPolicy::limits();
        $funded = (int) DB::table('wallet_topups')->where('wallet_id', $invoice->wallet_id)->where('status', 'SUCCESS')->sum('amount_vnd');
        if ((int) $current->amount_vnd < $limits['minimum'] || (int) $current->amount_vnd > $limits['maximum'] || $funded + (int) $current->amount_vnd > $limits['total']) return $review('TEST_LIMIT_EXCEEDED', (int) $invoice->id);
        $identity = implode(':', ['sepay', 'live', strtolower($event->gateway), $event->account_number, (string) $event->provider_transaction_id]);
        if (! self::complete((int) $invoice->id, ['provider' => 'SEPAY_LIVE', 'code' => $current->code, 'amount' => (int) $current->amount_vnd, 'identity' => $identity, 'success' => true, 'bankEventId' => $eventId])) return $review('POSTING_NOT_APPLIED', (int) $invoice->id);
        DB::table('sepay_webhook_events')->where('id', $eventId)->update(['processing' => 'TOPUP_APPLIED', 'top_up_id' => $invoice->id, 'review_reason' => null, 'reviewed_at' => now('UTC'), 'updated_at' => now()]);

        return true;
    }
}
