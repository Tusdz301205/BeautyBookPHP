<?php

namespace App\Services\Finance;

use App\Enums\AccountKind;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CustomerProfile;
use App\Models\PaymentLedgerEntry;
use App\Models\PaymentTransaction;
use App\Models\RefundRequest;
use App\Models\RoleAssignment;
use App\Services\Identity\SessionContext;
use App\Services\Operations\LoyaltyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private SessionContext $context) {}

    public function access(Request $request, Booking $booking, string $action = 'read'): string
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($user->account_kind === AccountKind::Customer) {
            $profile = CustomerProfile::where('user_id', $user->id)->first();
            abort_unless($action === 'read' && $profile && (int) $profile->id === (int) $booking->customer_profile_id, 403);
            return 'CUSTOMER';
        }
        $assignment = $this->context->current($request);
        if ($assignment && $action !== 'read') $assignment = RoleAssignment::whereKey($assignment->id)->where('user_id', $user->id)->lockForUpdate()->first();
        abort_unless($assignment, 403);
        $role = $assignment->role_name;
        if ($action === 'process') abort_unless($role === 'PLATFORM_ADMIN', 403);
        elseif (in_array($action, ['collect', 'verify'], true)) abort_unless(in_array($role, ['BUSINESS_OWNER', 'RECEPTIONIST'], true), 403);
        elseif (in_array($action, ['refund', 'review'], true)) abort_unless(in_array($role, ['BUSINESS_OWNER', 'PLATFORM_ADMIN'], true), 403);
        else abort_unless(in_array($role, ['BUSINESS_OWNER', 'RECEPTIONIST', 'PLATFORM_ADMIN'], true), 403);
        if ($role !== 'PLATFORM_ADMIN') {
            abort_unless((int) $assignment->business_id === (int) $booking->business_id, 403);
            if ($role === 'RECEPTIONIST' || $assignment->branch_id) abort_unless((int) $assignment->branch_id === (int) $booking->branch_id, 403);
        }
        return $role;
    }

    public function balance(Booking $booking): array
    {
        $transactions = PaymentTransaction::where('booking_id', $booking->id)->get();
        $collected = (int) $transactions->where('status', 'VERIFIED')->sum('amount_cents');
        $reversed = (int) $transactions->where('status', 'REVERSED')->sum('amount_cents');
        $pending = (int) $transactions->where('status', 'PENDING')->sum('amount_cents');
        $refunds = RefundRequest::whereIn('payment_transaction_id', $transactions->pluck('id'))->where('status', 'REFUNDED')->sum('amount_cents');
        $netCollected = $collected - $reversed;
        // A held (or applied) wallet deposit is part of the service price already paid, so the store only collects the rest.
        $deposit = $this->depositCents($booking);
        $total = Money::cents($booking->total_amount);
        // Data from before the unified rule: the deposit says the store was paid but no transaction exists. It still counts as paid.
        $legacy = $this->legacyStoreCents($booking, $transactions->where('status', 'VERIFIED')->isEmpty(), $deposit, $total);
        return ['total_cents' => $total, 'collected_cents' => $netCollected, 'refunded_cents' => (int) $refunds, 'deposit_cents' => $deposit, 'legacy_store_cents' => $legacy,
            'net_cents' => $netCollected + $legacy - (int) $refunds + $deposit, 'pending_cents' => $pending,
            'due_cents' => max(0, $total - $deposit - $netCollected - $legacy + (int) $refunds),
            // As in the original contract, an amount that was refunded stays due but is not collected a second time.
            'collectable_cents' => max(0, $total - $deposit - $netCollected - $legacy - $pending)];
    }

    /** The remainder a pre-rule store receipt stood for: only when the deposit is stamped and the booking has no verified payment at all. */
    private function legacyStoreCents(Booking $booking, bool $noVerifiedPayment, int $depositCents, int $totalCents): int
    {
        if (! $noVerifiedPayment || $depositCents <= 0) return 0;
        $stamped = DB::table('booking_deposits')->where('booking_id', $booking->id)->whereIn('status', ['HELD', 'PAID', 'APPLIED'])->whereNotNull('store_received_at')->exists();

        return $stamped ? max(0, $totalCents - $depositCents) : 0;
    }

    /** The booking's live wallet deposit in cents; a refunded or forfeited deposit is no longer a payment toward the price. */
    public function depositCents(Booking $booking): int
    {
        return (int) DB::table('booking_deposits')->where('booking_id', $booking->id)->whereIn('funding_source', ['SIMULATED_WALLET', 'DIRECT_SEPAY'])
            ->whereIn('status', ['HELD', 'PAID', 'APPLIED'])->value('amount_vnd') * 100;
    }

    /**
     * Sets or clears the deposit's "store payment received" mark so it always follows the money: set while a verified payment
     * (net of reversals and refunds) stands, cleared when none does. The mark blocks cancelling, so it must never outlive the money.
     * The deposit version moves whenever the mark changes, as the deposit view's optimistic check expects.
     */
    private function syncStoreReceipt(Booking $booking, ?int $actorId, ?string $reference): void
    {
        $deposit = DB::table('booking_deposits')->where('booking_id', $booking->id)->whereIn('status', ['HELD', 'PAID', 'APPLIED'])->lockForUpdate()->first();
        if (! $deposit) return;
        $balance = $this->balance($booking);
        $paid = $balance['collected_cents'] + $balance['legacy_store_cents'] - $balance['refunded_cents'] > 0;
        if ($paid && ! $deposit->store_received_at) {
            DB::table('booking_deposits')->where('id', $deposit->id)->update(['store_received_at' => now('UTC')->format('Y-m-d H:i:s'), 'store_received_by_id' => $actorId, 'store_reference' => $reference !== null ? mb_substr($reference, 0, 200) : null,
                'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        } elseif (! $paid && $deposit->store_received_at) {
            DB::table('booking_deposits')->where('id', $deposit->id)->update(['store_received_at' => null, 'store_received_by_id' => null, 'store_reference' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
    }

    /** Everything that follows a payment becoming verified, for every way of recording one: ledger, deposit mark, loyalty, platform fee, audit. */
    private function afterVerified(Booking $booking, PaymentTransaction $transaction, int $actorId, ?string $reference, string $audit): void
    {
        $this->ledger($actorId, $transaction, 'PAYMENT_RECEIVED');
        $this->syncStoreReceipt($booking, $actorId, $reference ?? 'payment:'.$transaction->id);
        app(LoyaltyService::class)->earnForBooking($booking->id, $actorId);
        app(SettlementService::class)->ensureFee($booking, $actorId);
        $this->audit($actorId, $booking, $audit, $transaction->id, ['method' => $transaction->method, 'amount_cents' => $transaction->amount_cents, 'reference' => $reference]);
    }

    /**
     * A deposit that still awaits its bank transfer means the price is not settled by the store: the transfer that follows would
     * pay the same price a second time. Read as a locking read, so it sees the deposit as it is now, not as the transaction began.
     */
    private function assertDepositNotAwaiting(Booking $booking): void
    {
        $deposit = DB::table('booking_deposits')->where('booking_id', $booking->id)->lockForUpdate()->first();
        abort_if($deposit && $deposit->status === 'AWAITING_PAYMENT', 409, 'Khách chưa chuyển khoản cọc cho lịch này; chưa thể ghi nhận hoặc xác minh khoản thu.');
    }

    /** Collecting is only allowed once the customer has arrived when a deposit already covers part of the price. */
    private function assertCollectable(Booking $booking): void
    {
        $this->assertDepositNotAwaiting($booking);
        abort_unless(in_array($booking->status, ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'COMPLETED'], true), 409, 'Trạng thái lịch không cho phép thu tiền.');
        // With a wallet or direct deposit the store collects the remainder only once the customer has arrived (deposit-core store-receipt).
        abort_if($this->depositCents($booking) > 0 && ! in_array($booking->status, ['CHECKED_IN', 'IN_PROGRESS', 'COMPLETED'], true), 409, 'Chỉ ghi nhận phần thu tại cơ sở sau khi khách đã đến.');
    }

    /**
     * The deposit view's "received at the store" action: one verified payment for what is still due, recorded exactly as the
     * payment screen would record it (same locks: business, booking, role, deposit; same ledger, loyalty, fee and audit).
     * The same request again replays the first answer; any other claim about money already recorded is refused.
     *
     * @param  array{reference:string,method?:string}  $data
     * @return array{transaction:PaymentTransaction,replayed:bool}
     */
    public function storeReceipt(Request $request, int $bookingId, int $version, array $data): array
    {
        $method = $data['method'] ?? 'CASH';
        abort_unless(in_array($method, ['CASH', 'BANK_TRANSFER'], true), 422, 'Phương thức thanh toán không hợp lệ.');
        $reference = $data['reference'];

        // Lock before the first read: a read taken while waiting for the lock would pin an older snapshot of every later read.
        $businessId = $this->businessOf((int) ($bookingId));
        return DB::transaction(function () use ($request, $bookingId, $version, $method, $reference, $businessId) {
            $booking = $this->lockBooking($bookingId, $businessId);
            $this->access($request, $booking, 'collect');
            $deposit = DB::table('booking_deposits')->where('booking_id', $booking->id)->lockForUpdate()->first();
            abort_unless($deposit, 404, 'Deposit not found');
            abort_unless(in_array($deposit->status, ['PAID', 'HELD', 'APPLIED'], true) && in_array($booking->status, ['CHECKED_IN', 'IN_PROGRESS', 'COMPLETED'], true), 409, 'Booking is not eligible for store payment');
            $actor = (int) $request->user()->id;

            // Already recorded through this door: the same claim replays, a different one is a conflict.
            $recorded = PaymentTransaction::where('booking_id', $booking->id)->where('status', 'VERIFIED')
                ->where(fn ($q) => $q->where('idempotency_key', 'like', 'store-receipt:'.$deposit->id.':%')->orWhere('idempotency_key', 'store-receipt:legacy:'.$deposit->id))
                ->whereDoesntHave('reversal')->orderByDesc('id')->first();
            if ($recorded) {
                abort_unless(($recorded->evidence['reference'] ?? null) === $reference && $recorded->method === $method, 409, 'Khoản thu tại cơ sở đã được ghi nhận với thông tin khác.');

                return ['transaction' => $recorded, 'replayed' => true];
            }
            // Recorded before this rule (a stamped deposit and no transaction): the same reference completes the books once.
            $balance = $this->balance($booking);
            if ($balance['legacy_store_cents'] > 0) {
                abort_unless((string) $deposit->store_reference === $reference, 409, 'Khoản thu tại cơ sở đã được ghi nhận với thông tin khác.');

                return ['transaction' => $this->materializeLegacy($booking, $deposit), 'replayed' => true];
            }

            abort_unless((int) $deposit->version === $version, 409, 'Payment changed; reload');
            if ($why = $this->storeBlocker($booking, $deposit, $balance)) abort(409, $why);
            $sequence = PaymentTransaction::where('booking_id', $booking->id)->where('idempotency_key', 'like', 'store-receipt:'.$deposit->id.':%')->count() + 1;
            $transaction = PaymentTransaction::create([
                'booking_id' => $booking->id, 'amount_cents' => $balance['collectable_cents'], 'currency' => $booking->currency, 'method' => $method, 'status' => 'VERIFIED',
                'idempotency_key' => 'store-receipt:'.$deposit->id.':'.$sequence, 'settlement_reference' => $method === 'BANK_TRANSFER' ? $reference : null,
                'evidence' => ['source' => 'STORE_RECEIPT', 'reference' => $reference, 'depositId' => (int) $deposit->id],
                'created_by' => $actor, 'verified_by' => $actor, 'verified_at' => now(),
            ]);
            $this->afterVerified($booking, $transaction, $actor, $reference, 'STORE_RECEIPT');

            return ['transaction' => $transaction, 'replayed' => false];
        }, 3);
    }

    /** Turns one pre-rule store receipt into the verified payment it stood for. The caller holds the business, booking and deposit locks. */
    public function materializeLegacy(Booking $booking, object $deposit): PaymentTransaction
    {
        $existing = PaymentTransaction::where('idempotency_key', 'store-receipt:legacy:'.$deposit->id)->first();
        if ($existing) return $existing;
        $total = Money::cents($booking->total_amount);
        $amount = $total - $this->depositCents($booking);
        abort_unless($deposit->store_received_at && $amount > 0 && ! PaymentTransaction::where('booking_id', $booking->id)->where('status', 'VERIFIED')->exists(), 409, 'Không có khoản thu tại cơ sở cũ cần chuyển.');
        $actor = (int) ($deposit->store_received_by_id ?: $booking->created_by_user_id);
        $transaction = PaymentTransaction::create([
            'booking_id' => $booking->id, 'amount_cents' => $amount, 'currency' => $booking->currency, 'method' => 'CASH', 'status' => 'VERIFIED', 'idempotency_key' => 'store-receipt:legacy:'.$deposit->id,
            'evidence' => ['source' => 'LEGACY_STORE_RECEIPT', 'reference' => $deposit->store_reference, 'depositId' => (int) $deposit->id], 'created_by' => $actor, 'verified_by' => $actor,
            'verified_at' => CarbonImmutable::parse($deposit->store_received_at, 'UTC'),
        ]);
        $this->afterVerified($booking, $transaction, $actor, $deposit->store_reference, 'STORE_RECEIPT_LEGACY');

        return $transaction;
    }

    /** Command entry: every stamped deposit without a payment becomes a payment, each in its own locked transaction. @return int how many were turned */
    public function reconcileLegacyStoreReceipts(): int
    {
        $turned = 0;
        foreach (DB::table('booking_deposits')->whereNotNull('store_received_at')->whereIn('status', ['HELD', 'PAID', 'APPLIED'])->orderBy('id')->pluck('booking_id') as $bookingId) {
            $businessId = $this->businessOf((int) $bookingId);
            $turned += DB::transaction(function () use ($bookingId, $businessId) {
                $booking = $this->lockBooking((int) $bookingId, $businessId);
                $deposit = DB::table('booking_deposits')->where('booking_id', $booking->id)->lockForUpdate()->first();
                if (! $deposit || ! $deposit->store_received_at || $this->balance($booking)['legacy_store_cents'] <= 0) return 0;
                $this->materializeLegacy($booking, $deposit);

                return 1;
            }, 3);
        }

        return $turned;
    }

    /**
     * Net money the store has recorded for a booking (verified minus reversed, locking read) and money still pending verification.
     * Used by the receipt side to refuse to stack a deposit on a price that was already paid in the store.
     *
     * @return array{recorded_cents:int,pending_cents:int}
     */
    public static function recordedMoney(int $bookingId): array
    {
        $rows = DB::table('payment_transactions')->where('booking_id', $bookingId)->lockForUpdate()->get(['status', 'amount_cents']);

        return ['recorded_cents' => (int) $rows->where('status', 'VERIFIED')->sum('amount_cents') - (int) $rows->where('status', 'REVERSED')->sum('amount_cents'), 'pending_cents' => (int) $rows->where('status', 'PENDING')->sum('amount_cents')];
    }

    /**
     * Read-only report of bookings whose recorded money disagrees with their deposit: money recorded (or pending) while the deposit
     * still awaits, or after it expired or was voided. Nothing is changed or deleted; the owner settles each case.
     *
     * @return list<array{booking_id:int,booking_code:string,deposit_status:string,recorded_cents:int,pending_cents:int}>
     */
    public function inconsistencies(): array
    {
        $found = [];
        foreach (DB::table('booking_deposits as d')->join('bookings as b', 'b.id', '=', 'd.booking_id')->whereIn('d.status', ['AWAITING_PAYMENT', 'EXPIRED', 'VOIDED'])->orderBy('b.id')->get(['b.id', 'b.booking_code', 'd.status']) as $row) {
            $money = self::recordedMoney((int) $row->id);
            if ($money['recorded_cents'] > 0 || $money['pending_cents'] > 0) {
                $found[] = ['booking_id' => (int) $row->id, 'booking_code' => $row->booking_code, 'deposit_status' => $row->status] + $money;
            }
        }

        return $found;
    }

    /** What the store has recorded for this booking, for the deposit view; null while nothing stands. */
    /**
     * Why the store cannot record "the remainder was paid here" right now, or null when it can. The deposit view's `canRecordStore`
     * and the action both ask this one question, so what the screen offers is exactly what the backend accepts.
     */
    private function storeBlocker(Booking $booking, ?object $deposit, array $balance): ?string
    {
        if (! $deposit || ! in_array($deposit->status, ['PAID', 'HELD', 'APPLIED'], true) || ! in_array($booking->status, ['CHECKED_IN', 'IN_PROGRESS', 'COMPLETED'], true)) return 'Booking is not eligible for store payment';
        if ($balance['legacy_store_cents'] > 0) return 'Khoản thu tại cơ sở đã được ghi nhận trước đây; hãy chuyển nó thành giao dịch thay vì ghi thêm.';
        if ($balance['pending_cents'] > 0) return 'Có giao dịch chuyển khoản đang chờ xác minh; hãy xác minh hoặc đảo trước khi ghi nhận thu tại cơ sở.';
        if ($balance['collectable_cents'] <= 0) return 'Khoản còn lại đã được ghi nhận qua màn thanh toán.';

        return null;
    }

    public function canRecordStore(Booking $booking): bool
    {
        $deposit = DB::table('booking_deposits')->where('booking_id', $booking->id)->first();

        return $this->storeBlocker($booking, $deposit, $this->balance($booking)) === null;
    }

    /**
     * What the salon's payment book says about one booking, for the deposit view. Every number has one meaning: `initialRemainingVnd` is
     * what the deposit terms said would be paid at the salon when the booking was made; `dueVnd` and `collectableVnd` are what is
     * left now. Each recorded payment is an entry with its own method, reference, who recorded it and when, and whether it was
     * reversed or partly refunded; the per-method breakdown adds up exactly the entries that still stand.
     */
    public function paymentView(Booking $booking): array
    {
        $balance = $this->balance($booking);
        $deposit = DB::table('booking_deposits')->where('booking_id', $booking->id)->first();
        $iso = fn ($value) => $value ? CarbonImmutable::parse($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value, $value instanceof \DateTimeInterface ? config('app.timezone') : 'UTC')->toISOString() : null;
        $originals = PaymentTransaction::where('booking_id', $booking->id)->whereNull('reversal_of_id')->orderBy('id')->get();
        $reversals = PaymentTransaction::where('booking_id', $booking->id)->whereNotNull('reversal_of_id')->get()->keyBy('reversal_of_id');
        $refunded = RefundRequest::whereIn('payment_transaction_id', $originals->pluck('id'))->where('status', 'REFUNDED')->selectRaw('payment_transaction_id, SUM(amount_cents) as cents')->groupBy('payment_transaction_id')->pluck('cents', 'payment_transaction_id');
        $userIds = $originals->pluck('verified_by')->merge($originals->pluck('created_by'))->merge($reversals->pluck('created_by'))->push($deposit?->store_received_by_id)->filter()->unique();
        $names = \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');
        $person = fn ($id) => $id ? ['id' => (int) $id, 'name' => $names[$id] ?? null] : null;
        $entries = [];
        $byMethod = [];
        foreach ($originals as $t) {
            $reversal = $reversals->get($t->id);
            $back = (int) ($refunded[$t->id] ?? 0);
            $entries[] = ['id' => $t->id, 'method' => $t->method, 'status' => $t->status, 'amountVnd' => Money::decimal($t->amount_cents), 'refundedVnd' => Money::decimal($back),
                'reference' => $t->settlement_reference ?? ($t->evidence['reference'] ?? null), 'recordedAt' => $iso($t->verified_at ?? $t->created_at), 'recordedBy' => $person($t->verified_by ?? $t->created_by),
                'reversed' => (bool) $reversal, 'reversedAt' => $iso($reversal?->verified_at ?? $reversal?->created_at), 'reversedBy' => $person($reversal?->verified_by ?? $reversal?->created_by),
                'legacy' => ($t->evidence['source'] ?? null) === 'LEGACY_STORE_RECEIPT'];
            if ($t->status === 'VERIFIED' && ! $reversal) $byMethod[$t->method] = ($byMethod[$t->method] ?? 0) + $t->amount_cents - $back;
        }
        if ($balance['legacy_store_cents'] > 0 && $deposit) {
            // Stamped before the unified rule and not yet turned into a transaction: shown, labelled, never merged with a newer reference.
            $entries[] = ['id' => null, 'method' => 'CASH', 'status' => 'VERIFIED', 'amountVnd' => Money::decimal($balance['legacy_store_cents']), 'refundedVnd' => Money::decimal(0), 'reference' => $deposit->store_reference,
                'recordedAt' => $iso($deposit->store_received_at), 'recordedBy' => $person($deposit->store_received_by_id), 'reversed' => false, 'reversedAt' => null, 'reversedBy' => null, 'legacy' => true];
            $byMethod['CASH'] = ($byMethod['CASH'] ?? 0) + $balance['legacy_store_cents'];
        }

        return ['totalVnd' => Money::decimal($balance['total_cents']), 'depositVnd' => Money::decimal($balance['deposit_cents']), 'initialRemainingVnd' => $deposit ? Money::decimal((int) $deposit->remaining_vnd * 100) : null,
            'storeCollectedVnd' => Money::decimal($balance['collected_cents'] + $balance['legacy_store_cents'] - $balance['refunded_cents']), 'pendingVnd' => Money::decimal($balance['pending_cents']),
            'dueVnd' => Money::decimal($balance['due_cents']), 'collectableVnd' => Money::decimal($balance['collectable_cents']),
            'breakdown' => array_values(array_map(fn ($method, $cents) => ['method' => $method, 'amountVnd' => Money::decimal($cents)], array_keys(array_filter($byMethod, fn ($c) => $c > 0)), array_filter($byMethod, fn ($c) => $c > 0))), 'entries' => $entries];
    }

    public function collect(Request $request, Booking $booking, array $data): PaymentTransaction
    {
        // Lock before the first read: a read taken while waiting for the lock would pin an older snapshot of every later read.
        $businessId = $this->businessOf((int) ($booking->id));
        return DB::transaction(function () use ($request, $booking, $data, $businessId) {
            $booking = $this->lockBooking($booking->id, $businessId);
            $this->access($request, $booking, 'collect');
            $this->assertCollectable($booking);
            $amount = Money::cents($data['amount']);
            app(FinancialPolicyService::class)->capturePolicy($booking);
            $existing = PaymentTransaction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->booking_id === (int) $booking->id && $existing->method === $data['method'] && $existing->amount_cents === $amount && ! $existing->reversal_of_id, 409, 'Mã yêu cầu đã được dùng cho giao dịch khác.');
                return $existing;
            }
            if ($amount <= 0 || $amount > $this->balance($booking)['collectable_cents']) $this->invalid('Số tiền vượt số dư cần thu hoặc đã được giữ bởi giao dịch chờ xác minh.');
            $verified = $data['method'] === 'CASH';
            $transaction = PaymentTransaction::create([
                'booking_id' => $booking->id, 'amount_cents' => $amount, 'currency' => $booking->currency,
                'method' => $data['method'], 'status' => $verified ? 'VERIFIED' : 'PENDING',
                'idempotency_key' => $data['idempotency_key'], 'evidence' => $data['evidence'] ?? null,
                'created_by' => $request->user()->id, 'verified_by' => $verified ? $request->user()->id : null, 'verified_at' => $verified ? now() : null,
            ]);
            if ($verified) $this->afterVerified($booking, $transaction, (int) $request->user()->id, null, 'COLLECT');
            else $this->audit((int) $request->user()->id, $booking, 'COLLECT', $transaction->id, ['method' => $transaction->method, 'amount_cents' => $transaction->amount_cents]);
            return $transaction;
        }, 3);
    }

    public function verify(Request $request, PaymentTransaction $transaction, array $data): PaymentTransaction
    {
        // Lock before the first read: a read taken while waiting for the lock would pin an older snapshot of every later read.
        $businessId = $this->businessOf((int) ($transaction->booking_id));
        return DB::transaction(function () use ($request, $transaction, $data, $businessId) {
            $booking = $this->lockBooking($transaction->booking_id, $businessId);
            $this->access($request, $booking, 'verify');
            $transaction = $transaction->fresh();
            abort_unless($transaction->method === 'BANK_TRANSFER', 422);
            if ($transaction->status === 'VERIFIED') {
                abort_unless($transaction->settlement_reference === trim($data['settlement_reference']), 409, 'Mã đối soát không khớp giao dịch đã xác minh.');
                return $transaction;
            }
            abort_unless($transaction->status === 'PENDING', 409);
            $this->assertDepositNotAwaiting($booking);
            $balance = $this->balance($booking);
            abort_unless($balance['collected_cents'] + $balance['legacy_store_cents'] + $balance['deposit_cents'] + $transaction->amount_cents <= $balance['total_cents'], 409, 'Xác minh vượt số tiền lịch hẹn.');
            $transaction->update(['status' => 'VERIFIED', 'settlement_reference' => trim($data['settlement_reference']), 'evidence' => $data['evidence'], 'verified_by' => $request->user()->id, 'verified_at' => now()]);
            $this->afterVerified($booking, $transaction, (int) $request->user()->id, null, 'VERIFY');
            return $transaction;
        }, 3);
    }

    public function reverse(Request $request, PaymentTransaction $transaction, array $data): PaymentTransaction
    {
        // Lock before the first read: a read taken while waiting for the lock would pin an older snapshot of every later read.
        $businessId = $this->businessOf((int) ($transaction->booking_id));
        return DB::transaction(function () use ($request, $transaction, $data, $businessId) {
            $booking = $this->lockBooking($transaction->booking_id, $businessId);
            $this->access($request, $booking, 'reverse');
            $transaction = $transaction->fresh();
            abort_unless($transaction->status === 'VERIFIED', 409, 'Chỉ đảo giao dịch đã xác minh.');
            $existing = PaymentTransaction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->reversal_of_id === (int) $transaction->id && ($existing->evidence['reason'] ?? null) === trim($data['reason']), 409);
                return $existing;
            }
            abort_unless(! $transaction->reversal()->exists(), 409, 'Giao dịch đã được đảo.');
            abort_if($transaction->refunds()->whereIn('status', ['PENDING', 'APPROVED', 'PROCESSING', 'REFUNDED'])->exists(), 409, 'Giao dịch có số tiền đã hoàn hoặc đang giữ để hoàn.');
            $reversal = PaymentTransaction::create([
                'booking_id' => $booking->id, 'amount_cents' => $transaction->amount_cents, 'currency' => $transaction->currency,
                'method' => $transaction->method, 'status' => 'REVERSED', 'reversal_of_id' => $transaction->id,
                'idempotency_key' => $data['idempotency_key'], 'created_by' => $request->user()->id,
                'verified_by' => $request->user()->id, 'verified_at' => now(), 'evidence' => ['reason' => trim($data['reason'])],
            ]);
            $this->ledger((int) $request->user()->id, $reversal, 'REVERSAL');
            $this->syncStoreReceipt($booking, $request->user()->id, null);
            app(LoyaltyService::class)->reconcileEarn($booking->id, 'REVERSE:'.$reversal->id, $request->user()->id);
            $this->audit((int) $request->user()->id, $booking, 'REVERSE', $reversal->id, ['method' => $reversal->method, 'amount_cents' => $reversal->amount_cents, 'reversal_of' => $transaction->id]);
            return $reversal;
        }, 3);
    }

    public function requestRefund(Request $request, PaymentTransaction $transaction, array $data): RefundRequest
    {
        // Lock before the first read: a read taken while waiting for the lock would pin an older snapshot of every later read.
        $businessId = $this->businessOf((int) ($transaction->booking_id));
        return DB::transaction(function () use ($request, $transaction, $data, $businessId) {
            $booking = $this->lockBooking($transaction->booking_id, $businessId);
            $this->access($request, $booking, 'refund');
            $transaction = $transaction->fresh();
            abort_unless($transaction->status === 'VERIFIED' && ! $transaction->reversal()->exists(), 409, 'Giao dịch không thể hoàn tiền.');
            $amount = Money::cents($data['amount']);
            $reserved = (int) $transaction->refunds()->whereIn('status', ['PENDING', 'APPROVED', 'PROCESSING', 'REFUNDED'])->sum('amount_cents');
            if ($amount <= 0 || $reserved + $amount > $transaction->amount_cents) $this->invalid('Số tiền hoàn vượt số tiền còn được hoàn.');
            $refund = RefundRequest::create(['payment_transaction_id' => $transaction->id, 'amount_cents' => $amount, 'reason' => trim($data['reason']), 'requested_by' => $request->user()->id, 'status' => 'PENDING']);
            $this->audit((int) $request->user()->id, $booking, 'REFUND_REQUEST', $refund->id, ['amount_cents' => $amount]);
            return $refund;
        }, 3);
    }

    public function reviewRefund(Request $request, RefundRequest $refund, array $data): RefundRequest
    {
        // Lock before the first read: a read taken while waiting for the lock would pin an older snapshot of every later read.
        $businessId = $this->businessOf((int) ($refund->transaction->booking_id));
        return DB::transaction(function () use ($request, $refund, $data, $businessId) {
            $booking = $this->lockBooking($refund->transaction->booking_id, $businessId);
            $this->access($request, $booking, 'review');
            $refund = $refund->fresh();
            abort_unless($refund->status === 'PENDING', 409);
            abort_if((int) $refund->requested_by === (int) $request->user()->id, 403, 'Người tạo không được tự duyệt yêu cầu hoàn tiền.');
            $refund->update(['status' => $data['approve'] ? 'APPROVED' : 'REJECTED', 'reviewed_by' => $request->user()->id, 'review_note' => $data['note'] ?? null, 'reviewed_at' => now()]);
            $this->audit((int) $request->user()->id, $booking, 'REFUND_REVIEW', $refund->id);
            return $refund;
        }, 3);
    }

    public function processRefund(Request $request, RefundRequest $refund, array $data): RefundRequest
    {
        // Lock before the first read: a read taken while waiting for the lock would pin an older snapshot of every later read.
        $businessId = $this->businessOf((int) ($refund->transaction->booking_id));
        return DB::transaction(function () use ($request, $refund, $data, $businessId) {
            $booking = $this->lockBooking($refund->transaction->booking_id, $businessId);
            $this->access($request, $booking, 'process');
            $refund = $refund->fresh();
            if ($refund->status === 'REFUNDED') return $refund;
            if ($data['action'] === 'START') {
                if ($refund->status === 'PROCESSING') return $refund;
                abort_unless($refund->status === 'APPROVED', 409);
                $refund->update(['status' => 'PROCESSING', 'processing_started_at' => now(), 'processed_by' => $request->user()->id]);
            } elseif ($data['action'] === 'FAIL') {
                abort_unless($refund->status === 'PROCESSING', 409);
                $refund->update(['status' => 'FAILED', 'failure_reason' => trim($data['failure_reason']), 'processed_by' => $request->user()->id]);
            } else {
                abort_unless($refund->status === 'PROCESSING', 409);
                $transaction = $refund->transaction;
                $completed = (int) $transaction->refunds()->where('status', 'REFUNDED')->sum('amount_cents');
                abort_unless(! $transaction->reversal()->exists() && $completed + $refund->amount_cents <= $transaction->amount_cents, 409, 'Hoàn tiền vượt số tiền đã thu.');
                $refund->update(['status' => 'REFUNDED', 'settlement_reference' => trim($data['settlement_reference']), 'processed_by' => $request->user()->id, 'processed_at' => now()]);
                $this->ledger((int) $request->user()->id, $transaction, 'REFUND', $refund);
                // Money that went back to the customer is no longer received at the store: the cancel guard must lift with it.
                $this->syncStoreReceipt($booking, $request->user()->id, null);
                app(LoyaltyService::class)->reconcileEarn($booking->id, 'REFUND:'.$refund->id, $request->user()->id);
                app(SettlementService::class)->adjustRefund($refund, $request->user()->id);
            }
            $this->audit((int) $request->user()->id, $booking, 'REFUND_'.$data['action'], $refund->id);
            return $refund;
        }, 3);
    }

    private function ledger(int $actorId, PaymentTransaction $transaction, string $type, ?RefundRequest $refund = null): void
    {
        PaymentLedgerEntry::firstOrCreate(['idempotency_key' => 'ledger:'.$type.':'.($refund?->id ?? $transaction->id)], [
            'booking_id' => $transaction->booking_id, 'payment_transaction_id' => $transaction->id, 'refund_request_id' => $refund?->id,
            'type' => $type, 'direction' => $type === 'PAYMENT_RECEIVED' ? 'CREDIT' : 'DEBIT', 'amount_cents' => $refund?->amount_cents ?? $transaction->amount_cents,
            'actor_id' => $actorId, 'created_at' => now(),
        ]);
    }
    private function audit(int $actorId, Booking $booking, string $action, int $id, array $extra = []): void
    {
        DB::table('audit_logs')->insert(['user_id' => $actorId, 'business_id' => $booking->business_id, 'action' => $action, 'entity_type' => 'Payment', 'entity_id' => (string) $id, 'metadata' => json_encode(['booking_id' => $booking->id] + $extra, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
    private function invalid(string $message): never { throw ValidationException::withMessages(['amount' => $message]); }

    /** The business of a booking, read before any transaction opens (never inside one: it would pin the snapshot). */
    private function businessOf(int $bookingId): int
    {
        return (int) Booking::query()->whereKey($bookingId)->value('business_id') ?: abort(404);
    }

    /**
     * Business, then booking: the one lock order for every payment write. Both are locking reads and nothing is read before
     * them, so under REPEATABLE READ the transaction's view starts after the locks are held and sees every committed payment.
     */
    private function lockBooking(int $id, int $businessId): Booking
    {
        Business::whereKey($businessId)->lockForUpdate()->firstOrFail();
        $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $booking->business_id === $businessId, 409, 'Lịch hẹn đã thay đổi doanh nghiệp.');

        return $booking;
    }
}
