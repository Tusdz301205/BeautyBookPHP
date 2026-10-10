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
        return ['total_cents' => Money::cents($booking->total_amount), 'collected_cents' => $netCollected, 'refunded_cents' => (int) $refunds,
            'net_cents' => $netCollected - (int) $refunds, 'pending_cents' => $pending,
            'due_cents' => max(0, Money::cents($booking->total_amount) - $netCollected + (int) $refunds),
            'collectable_cents' => max(0, Money::cents($booking->total_amount) - $netCollected - $pending)];
    }

    public function collect(Request $request, Booking $booking, array $data): PaymentTransaction
    {
        return DB::transaction(function () use ($request, $booking, $data) {
            $booking = $this->lockBooking($booking->id);
            $this->access($request, $booking, 'collect');
            abort_unless(in_array($booking->status, ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'COMPLETED'], true), 409, 'Trạng thái lịch không cho phép thu tiền.');
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
            if ($verified) {
                $this->ledger($request, $transaction, 'PAYMENT_RECEIVED');
                app(LoyaltyService::class)->earnForBooking($booking->id, $request->user()->id);
                app(SettlementService::class)->ensureFee($booking, $request->user()->id);
            }
            $this->audit($request, $booking, 'COLLECT', $transaction->id);
            return $transaction;
        }, 3);
    }

    public function verify(Request $request, PaymentTransaction $transaction, array $data): PaymentTransaction
    {
        return DB::transaction(function () use ($request, $transaction, $data) {
            $booking = $this->lockBooking($transaction->booking_id);
            $this->access($request, $booking, 'verify');
            $transaction = $transaction->fresh();
            abort_unless($transaction->method === 'BANK_TRANSFER', 422);
            if ($transaction->status === 'VERIFIED') {
                abort_unless($transaction->settlement_reference === trim($data['settlement_reference']), 409, 'Mã đối soát không khớp giao dịch đã xác minh.');
                return $transaction;
            }
            abort_unless($transaction->status === 'PENDING', 409);
            $balance = $this->balance($booking);
            abort_unless($balance['collected_cents'] + $transaction->amount_cents <= $balance['total_cents'], 409, 'Xác minh vượt số tiền lịch hẹn.');
            $transaction->update(['status' => 'VERIFIED', 'settlement_reference' => trim($data['settlement_reference']), 'evidence' => $data['evidence'], 'verified_by' => $request->user()->id, 'verified_at' => now()]);
            $this->ledger($request, $transaction, 'PAYMENT_RECEIVED');
            app(LoyaltyService::class)->earnForBooking($booking->id, $request->user()->id);
            app(SettlementService::class)->ensureFee($booking, $request->user()->id);
            $this->audit($request, $booking, 'VERIFY', $transaction->id);
            return $transaction;
        }, 3);
    }

    public function reverse(Request $request, PaymentTransaction $transaction, array $data): PaymentTransaction
    {
        return DB::transaction(function () use ($request, $transaction, $data) {
            $booking = $this->lockBooking($transaction->booking_id);
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
            $this->ledger($request, $reversal, 'REVERSAL');
            app(LoyaltyService::class)->reconcileEarn($booking->id, 'REVERSE:'.$reversal->id, $request->user()->id);
            $this->audit($request, $booking, 'REVERSE', $reversal->id);
            return $reversal;
        }, 3);
    }

    public function requestRefund(Request $request, PaymentTransaction $transaction, array $data): RefundRequest
    {
        return DB::transaction(function () use ($request, $transaction, $data) {
            $booking = $this->lockBooking($transaction->booking_id);
            $this->access($request, $booking, 'refund');
            $transaction = $transaction->fresh();
            abort_unless($transaction->status === 'VERIFIED' && ! $transaction->reversal()->exists(), 409, 'Giao dịch không thể hoàn tiền.');
            $amount = Money::cents($data['amount']);
            $reserved = (int) $transaction->refunds()->whereIn('status', ['PENDING', 'APPROVED', 'PROCESSING', 'REFUNDED'])->sum('amount_cents');
            if ($amount <= 0 || $reserved + $amount > $transaction->amount_cents) $this->invalid('Số tiền hoàn vượt số tiền còn được hoàn.');
            $refund = RefundRequest::create(['payment_transaction_id' => $transaction->id, 'amount_cents' => $amount, 'reason' => trim($data['reason']), 'requested_by' => $request->user()->id, 'status' => 'PENDING']);
            $this->audit($request, $booking, 'REFUND_REQUEST', $refund->id);
            return $refund;
        }, 3);
    }

    public function reviewRefund(Request $request, RefundRequest $refund, array $data): RefundRequest
    {
        return DB::transaction(function () use ($request, $refund, $data) {
            $booking = $this->lockBooking($refund->transaction->booking_id);
            $this->access($request, $booking, 'review');
            $refund = $refund->fresh();
            abort_unless($refund->status === 'PENDING', 409);
            abort_if((int) $refund->requested_by === (int) $request->user()->id, 403, 'Người tạo không được tự duyệt yêu cầu hoàn tiền.');
            $refund->update(['status' => $data['approve'] ? 'APPROVED' : 'REJECTED', 'reviewed_by' => $request->user()->id, 'review_note' => $data['note'] ?? null, 'reviewed_at' => now()]);
            $this->audit($request, $booking, 'REFUND_REVIEW', $refund->id);
            return $refund;
        }, 3);
    }

    public function processRefund(Request $request, RefundRequest $refund, array $data): RefundRequest
    {
        return DB::transaction(function () use ($request, $refund, $data) {
            $booking = $this->lockBooking($refund->transaction->booking_id);
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
                $this->ledger($request, $transaction, 'REFUND', $refund);
                app(LoyaltyService::class)->reconcileEarn($booking->id, 'REFUND:'.$refund->id, $request->user()->id);
                app(SettlementService::class)->adjustRefund($refund, $request->user()->id);
            }
            $this->audit($request, $booking, 'REFUND_'.$data['action'], $refund->id);
            return $refund;
        }, 3);
    }

    private function ledger(Request $request, PaymentTransaction $transaction, string $type, ?RefundRequest $refund = null): void
    {
        PaymentLedgerEntry::firstOrCreate(['idempotency_key' => 'ledger:'.$type.':'.($refund?->id ?? $transaction->id)], [
            'booking_id' => $transaction->booking_id, 'payment_transaction_id' => $transaction->id, 'refund_request_id' => $refund?->id,
            'type' => $type, 'direction' => $type === 'PAYMENT_RECEIVED' ? 'CREDIT' : 'DEBIT', 'amount_cents' => $refund?->amount_cents ?? $transaction->amount_cents,
            'actor_id' => $request->user()->id, 'created_at' => now(),
        ]);
    }
    private function audit(Request $request, Booking $booking, string $action, int $id): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'business_id' => $booking->business_id, 'action' => $action, 'entity_type' => 'Payment', 'entity_id' => (string) $id, 'metadata' => json_encode(['booking_id' => $booking->id], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
    private function invalid(string $message): never { throw ValidationException::withMessages(['amount' => $message]); }

    private function lockBooking(int $id): Booking
    {
        $reference = Booking::findOrFail($id);
        Business::whereKey($reference->business_id)->lockForUpdate()->firstOrFail();
        return Booking::whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
