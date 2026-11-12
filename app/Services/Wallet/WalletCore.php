<?php

namespace App\Services\Wallet;

use App\Services\Booking\BookingNotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Wallet ledger and booking-deposit rules (wallet-core.ts). Every balance change is one equal debit/credit pair in
 * wallet_transfers, written in the caller's transaction with a unique operation key, so a replay posts once.
 * Lock order everywhere: user -> customer profile -> wallet -> invoice/deposit -> receipt.
 */
class WalletCore
{
    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{fundingSource:string,totalVnd:int,depositVnd:int,remainingVnd:int,rateBps:int,cancellationHours:int,quote:string} */
    public static function terms(int $branchId, int|float|string $total, int $cancellationHours): array
    {
        if (! is_numeric($total) || $total < 0 || $total > 999999999999) abort(400, 'Invalid booking amount');
        $payable = (int) round((float) $total, 0, PHP_ROUND_HALF_UP);
        $deposit = intdiv($payable * 3 + 9, 10);
        $terms = ['fundingSource' => 'SIMULATED_WALLET', 'totalVnd' => $payable, 'depositVnd' => $deposit, 'remainingVnd' => $payable - $deposit, 'rateBps' => 3000, 'cancellationHours' => $cancellationHours];

        return $terms + ['quote' => self::hash(['version' => 1, 'branchId' => $branchId] + $terms)];
    }

    /** Creates the wallet on first use and locks its row. */
    public static function lock(int $customerProfileId, string $scope = 'DEMO'): object
    {
        DB::table('wallets')->insertOrIgnore(['customer_profile_id' => $customerProfileId, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('wallets')->where('customer_profile_id', $customerProfileId)->where('scope', $scope)->lockForUpdate()->first();
    }

    /**
     * @param array{operationKey:string,kind:string,amount:int,walletId?:int,businessId?:int,availableDelta?:int,heldDelta?:int,actorId?:?int,reason:string} $i
     */
    public static function post(array $i): object
    {
        if ($i['amount'] <= 0) abort(400, 'A ledger transfer must be positive');
        $prior = DB::table('wallet_transfers')->where('operation_key', $i['operationKey'])->first();
        if ($prior) {
            if ((int) $prior->amount_vnd !== $i['amount'] || $prior->kind !== $i['kind'] || ($prior->wallet_id === null ? null : (int) $prior->wallet_id) !== ($i['walletId'] ?? null)) abort(409, 'Financial operation identity conflict');

            return $prior;
        }
        $available = $i['availableDelta'] ?? 0;
        $held = $i['heldDelta'] ?? 0;
        $wallet = isset($i['walletId']) ? 'wallet:'.$i['walletId'] : null;
        $snapshot = null;
        if ($wallet) {
            $changed = DB::update('UPDATE wallets SET available_vnd = available_vnd + ?, held_vnd = held_vnd + ?, version = version + 1, updated_at = ? WHERE id = ? AND available_vnd >= ? AND held_vnd >= ?',
                [$available, $held, now(), $i['walletId'], $available < 0 ? -$available : 0, $held < 0 ? -$held : 0]);
            if ($changed !== 1) abort($available < 0 ? 402 : 409, $available < 0 ? 'Insufficient wallet balance' : 'Held wallet balance is inconsistent');
            $snapshot = DB::table('wallets')->find($i['walletId']);
        }
        $accounts = [
            'TOPUP' => [($snapshot?->scope === 'LIVE_LAB' ? 'lab:credit-clearing' : 'demo:clearing'), $wallet.':available'],
            'HOLD' => [$wallet.':available', $wallet.':held'],
            'REFUND' => [$wallet.':held', $wallet.':available'],
            'RELEASE' => [$wallet.':held', 'business:'.($i['businessId'] ?? '').':payable'],
            'SETTLEMENT' => ['business:'.($i['businessId'] ?? '').':payable', 'demo:settled'],
        ];
        $pair = $accounts[$i['kind']] ?? abort(400, 'Invalid ledger operation');
        $id = DB::table('wallet_transfers')->insertGetId(['wallet_id' => $i['walletId'] ?? null, 'operation_key' => $i['operationKey'], 'kind' => $i['kind'], 'from_account' => $pair[0], 'to_account' => $pair[1],
            'amount_vnd' => $i['amount'], 'actor_id' => $i['actorId'] ?? null, 'reason' => $i['reason'], 'available_after_vnd' => $snapshot?->available_vnd, 'held_after_vnd' => $snapshot?->held_vnd, 'wallet_version' => $snapshot?->version]);

        return DB::table('wallet_transfers')->find($id);
    }

    private static function audit(?int $actorId, ?int $businessId, string $action, string $entityType, int $entityId, array $metadata): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actorId, 'business_id' => $businessId, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => (string) $entityId, 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }

    /**
     * Moves the 30% deposit from the customer's available balance to held, in the booking's transaction.
     * A failed booking therefore rolls the hold back with it. Returns null for a zero deposit.
     *
     * @param array{bookingId:int,customerProfileId:int,actorId:int,branchId:int,businessId:int,total:int|float|string,cancellationHours:int,confirmationMode:string,pendingHoldMinutes:int,now:\Carbon\CarbonInterface,startAt:\Carbon\CarbonInterface,acceptedQuote?:?string} $i
     */
    public static function hold(array $i): ?object
    {
        $terms = self::terms($i['branchId'], $i['total'], $i['cancellationHours']);
        if ($terms['depositVnd'] === 0) return null;
        if (($i['acceptedQuote'] ?? null) !== $terms['quote']) abort(409, 'Review the current wallet deposit before booking');
        LiveWalletPolicy::requireSpend($i['actorId'], $i['businessId']);
        $wallet = self::lock($i['customerProfileId'], 'LIVE_LAB');
        $id = DB::table('booking_deposits')->insertGetId(['booking_id' => $i['bookingId'], 'wallet_id' => $wallet->id, 'funding_source' => 'SIMULATED_WALLET', 'payment_code' => 'WM'.strtoupper(substr(bin2hex(random_bytes(12)), 0, 24)),
            'total_vnd' => $terms['totalVnd'], 'amount_vnd' => $terms['depositVnd'], 'remaining_vnd' => $terms['remainingVnd'], 'rate_bps' => 3000, 'status' => 'HELD', 'environment' => 'test',
            'confirmation_mode' => $i['confirmationMode'], 'pending_hold_minutes' => $i['pendingHoldMinutes'], 'cancellation_hours' => $i['cancellationHours'],
            'expires_at' => min($i['now']->copy()->addMinutes($i['pendingHoldMinutes']), $i['startAt'])->utc()->format('Y-m-d H:i:s'), 'paid_at' => $i['now']->utc()->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now()]);
        self::post(['operationKey' => 'hold:'.$id, 'kind' => 'HOLD', 'walletId' => $wallet->id, 'amount' => $terms['depositVnd'], 'availableDelta' => -$terms['depositVnd'], 'heldDelta' => $terms['depositVnd'], 'actorId' => $i['actorId'], 'reason' => 'BOOKING_DEPOSIT_HELD']);

        return DB::table('booking_deposits')->find($id);
    }

    /**
     * Holds a customer's deposit inside the booking's own transaction (called by CustomerBookingFinalizer for every
     * create path). The cancellation cutoff, hold length and confirmation mode are the branch's own rules and are
     * snapshotted on the deposit, so a later policy change never alters a deposit that already exists.
     *
     * @param array{cancellationHours:int,pendingHoldMinutes:int,confirmationMode:string}|null $rules
     */
    public static function holdForCustomerBooking(object $booking, array $context, object $branch, int|float|string $total, \Carbon\CarbonInterface|string $startAt, ?string $acceptedQuote, ?array $rules = null): ?object
    {
        if ($context['kind'] !== 'CUSTOMER' || ! LiveWalletPolicy::walletMode()) return null;
        $rules ??= \App\Services\Booking\BranchBookingRules::for((int) $branch->id);

        return self::hold(['bookingId' => (int) $booking->id, 'customerProfileId' => (int) $context['customer_profile_id'], 'actorId' => (int) $context['actor_user_id'], 'branchId' => (int) $branch->id,
            'businessId' => (int) $branch->business_id, 'total' => $total, 'cancellationHours' => $rules['cancellationHours'], 'confirmationMode' => $rules['confirmationMode'],
            'pendingHoldMinutes' => $rules['pendingHoldMinutes'], 'now' => now('UTC'),
            'startAt' => \Carbon\CarbonImmutable::parse($startAt, 'UTC'), 'acceptedQuote' => $acceptedQuote]);
    }

    /** Entry point for every booking status change (lifecycle, expiry worker, approved cancellation request). No deposit = nothing to do. */
    public static function transition(int $bookingId, string $target, array $options = []): void
    {
        $deposit = DB::table('booking_deposits')->where('booking_id', $bookingId)->lockForUpdate()->first();
        if (! $deposit) return;
        if ($deposit->funding_source === 'SIMULATED_WALLET') self::resolve($deposit, $target, $options);
        elseif ($deposit->funding_source === \App\Services\Payments\DirectDeposit::FUNDING) \App\Services\Payments\DirectDeposit::resolve($deposit, $target, $options);
    }

    /**
     * Applies a booking status to its held deposit. COMPLETED, a late customer cancellation and NO_SHOW release the
     * deposit to the business as a payable; every other end state refunds it to the wallet. Caller holds the booking lock.
     *
     * @param array{customerCancelled?:bool,requestedAt?:?\Carbon\CarbonInterface,now?:?\Carbon\CarbonInterface,actorId?:?int} $options
     */
    public static function resolve(object $deposit, string $target, array $options = []): void
    {
        if (in_array($target, ['CONFIRMED', 'CHECKED_IN', 'COMPLETED', 'NO_SHOW'], true) && ! in_array($deposit->status, ['HELD', 'APPLIED', 'FORFEITED'], true)) abort(409, 'Wallet deposit is not held');
        if (! in_array($target, ['COMPLETED', 'CANCELLED', 'REJECTED', 'EXPIRED', 'NO_SHOW'], true) || $deposit->status !== 'HELD') return;
        if ($deposit->store_received_at && in_array($target, ['CANCELLED', 'REJECTED', 'EXPIRED'], true)) abort(409, 'Resolve the recorded store payment before cancelling');
        $ref = DB::table('wallets')->find($deposit->wallet_id);
        $wallet = self::lock((int) $ref->customer_profile_id, $ref->scope);
        $now = $options['now'] ?? now('UTC');
        $booking = DB::table('bookings')->where('id', $deposit->booking_id)->first(['business_id', 'appointment_start_at']);
        $startAt = \Carbon\CarbonImmutable::parse($booking->appointment_start_at, 'UTC');
        $forfeited = $target === 'NO_SHOW' || (! empty($options['customerCancelled']) && ($options['requestedAt'] ?? $now)->greaterThan($startAt->subHours((int) $deposit->cancellation_hours)));
        $release = $target === 'COMPLETED' || $forfeited;
        $reason = $forfeited ? 'DEPOSIT_FORFEITED_'.$target : 'BOOKING_'.$target;
        $businessId = (int) $booking->business_id;
        self::post(['operationKey' => 'resolve:'.$deposit->id, 'kind' => $release ? 'RELEASE' : 'REFUND', 'walletId' => $wallet->id, 'businessId' => $businessId, 'amount' => (int) $deposit->amount_vnd,
            'heldDelta' => -(int) $deposit->amount_vnd, 'availableDelta' => $release ? 0 : (int) $deposit->amount_vnd, 'actorId' => $options['actorId'] ?? null, 'reason' => $reason]);
        if ($release) {
            DB::table('business_payables')->insert(['deposit_id' => $deposit->id, 'business_id' => $businessId, 'amount_vnd' => $deposit->amount_vnd, 'reason' => $forfeited ? 'CUSTOMER_FORFEITURE' : 'COMPLETED_SERVICE', 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('deposit_refunds')->insert(['deposit_id' => $deposit->id, 'source' => 'SIMULATED_WALLET', 'operation_key' => 'resolve:'.$deposit->id, 'amount_vnd' => $deposit->amount_vnd, 'reason' => $reason,
                'status' => 'CONFIRMED', 'reference' => 'resolve:'.$deposit->id, 'reported_at' => $now->utc()->format('Y-m-d H:i:s'), 'confirmed_at' => $now->utc()->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now()]);
            $userId = (int) DB::table('customer_profiles')->where('id', $wallet->customer_profile_id)->value('user_id');
            app(BookingNotificationService::class)->record($userId, 'WALLET_REFUNDED', 'Cọc đã hoàn về ví', 'Khoản cọc của lịch hẹn đã được hoàn về ví '.($wallet->scope === 'LIVE_LAB' ? 'Live lab' : 'thử nghiệm').'.', (int) $deposit->booking_id, 'wallet-refund:'.$deposit->id, 'INFO', ['simulation' => true]);
        }
        DB::table('booking_deposits')->where('id', $deposit->id)->update(['status' => $release ? ($forfeited ? 'FORFEITED' : 'APPLIED') : 'REFUNDED', 'version' => $deposit->version + 1, 'updated_at' => now()]);
        self::audit($options['actorId'] ?? null, $businessId, $wallet->scope === 'LIVE_LAB' ? ($release ? 'LIVE_LAB_DEPOSIT_RELEASED' : 'LIVE_LAB_DEPOSIT_REFUNDED') : ($release ? 'SIMULATED_DEPOSIT_RELEASED' : 'SIMULATED_DEPOSIT_REFUNDED'),
            'BookingDeposit', (int) $deposit->id, ['amountVnd' => (string) $deposit->amount_vnd, 'reason' => $reason, 'walletScope' => $wallet->scope, 'internalTest' => true]);
    }
}
