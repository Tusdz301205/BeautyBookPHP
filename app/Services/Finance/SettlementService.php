<?php

namespace App\Services\Finance;

use App\Models\Booking;
use App\Models\Business;
use App\Models\PaymentLedgerEntry;
use App\Models\RefundRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettlementService
{
    public function __construct(private FinanceScope $scope) {}

    public function ensureFee(Booking $booking, ?int $actorId = null): ?object
    {
        return DB::transaction(function () use ($booking, $actorId) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($booking->status !== 'COMPLETED') return null;
            $balance = app(PaymentService::class)->balance($booking);
            if ($balance['net_cents'] < $balance['total_cents']) return null;
            $fee = DB::table('platform_fee_entries')->where('booking_id', $booking->id)->first();
            if ($fee) return $fee;
            $rate = Money::cents((string) env('PLATFORM_FEE_RATE_PERCENT', '5'));
            abort_if($rate > 10000, 422);
            $amount = Money::multiplyDivideRound($balance['total_cents'],$rate,10000);
            $id = DB::table('platform_fee_entries')->insertGetId(['business_id' => $booking->business_id, 'branch_id' => $booking->branch_id, 'booking_id' => $booking->id,
                'base_cents' => $balance['total_cents'], 'fee_rate_basis_points' => $rate, 'fee_cents' => $amount, 'status' => 'OPEN',
                'calculation_snapshot' => json_encode(['base_cents' => $balance['total_cents'], 'rate_basis_points' => $rate, 'policy' => 'DEFAULT_PLATFORM_FEE'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            PaymentLedgerEntry::create(['booking_id' => $booking->id, 'type' => 'PLATFORM_FEE', 'direction' => 'DEBIT', 'amount_cents' => $amount, 'idempotency_key' => 'ledger:fee:'.$id, 'actor_id' => $actorId ?? $booking->created_by_user_id, 'created_at' => now()]);
            return DB::table('platform_fee_entries')->find($id);
        }, 3);
    }

    public function adjustRefund(RefundRequest $refund, ?int $actorId = null): void
    {
        $bookingId = $refund->transaction->booking_id;
        $fee = DB::table('platform_fee_entries')->where('booking_id', $bookingId)->first();
        if (! $fee || $refund->status !== 'REFUNDED') return;
        $amount = Money::multiplyDivideRound($refund->amount_cents,$fee->fee_rate_basis_points,10000);
        DB::table('platform_fee_adjustments')->insertOrIgnore(['platform_fee_entry_id' => $fee->id, 'refund_request_id' => $refund->id, 'amount_cents' => $amount, 'reason' => 'Điều chỉnh phí theo hoàn tiền #'.$refund->id, 'created_at' => now(), 'updated_at' => now()]);
        PaymentLedgerEntry::firstOrCreate(['idempotency_key' => 'ledger:fee-refund:'.$refund->id], ['booking_id' => $bookingId, 'refund_request_id' => $refund->id, 'type' => 'PLATFORM_FEE_ADJUSTMENT', 'direction' => 'CREDIT', 'amount_cents' => $amount, 'actor_id' => $actorId ?? $refund->processed_by, 'created_at' => now()]);
    }

    public function generate(Request $request, int $businessId, array $data): object
    {
        $this->scope->business($request, $businessId, ['PLATFORM_ADMIN']);
        return DB::transaction(function () use ($businessId, $data) {
            Business::whereKey($businessId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('platform_statements')->where('business_id', $businessId)->where('period_start', $data['period_start'])->where('period_end', $data['period_end'])->first();
            if ($existing) return $existing;
            $from = \Carbon\Carbon::parse($data['period_start'])->startOfDay(); $to = \Carbon\Carbon::parse($data['period_end'])->endOfDay();
            $fees = DB::table('platform_fee_entries')->where('business_id', $businessId)->whereBetween('created_at', [$from, $to])->get();
            $adjustments = DB::table('platform_fee_adjustments')->whereIn('platform_fee_entry_id', DB::table('platform_fee_entries')->where('business_id', $businessId)->select('id'))->whereBetween('created_at', [$from, $to])->get();
            $lines = $fees->map(fn ($f) => ['type' => 'FEE', 'fee_id' => $f->id, 'booking_id' => $f->booking_id, 'amount_cents' => $f->fee_cents, 'base_cents' => $f->base_cents, 'rate_basis_points' => $f->fee_rate_basis_points])->merge($adjustments->map(fn ($a) => ['type' => 'ADJUSTMENT', 'adjustment_id' => $a->id, 'refund_id' => $a->refund_request_id, 'amount_cents' => -$a->amount_cents, 'reason' => $a->reason]));
            $gross = (int) $fees->sum('fee_cents'); $adjustment = (int) $adjustments->sum('amount_cents');
            $id = DB::table('platform_statements')->insertGetId(['business_id' => $businessId, 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'gross_fee_cents' => $gross, 'adjustment_cents' => $adjustment, 'net_cents' => $gross - $adjustment, 'lines' => json_encode($lines->values()->all(), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('platform_fee_entries')->whereIn('id', $fees->pluck('id'))->update(['status' => 'STATEMENTED', 'updated_at' => now()]);
            return DB::table('platform_statements')->find($id);
        }, 3);
    }

    public function transition(Request $request, int $id, array $data): object
    {
        return DB::transaction(function () use ($request, $id, $data) {
            $statement = DB::table('platform_statements')->where('id', $id)->lockForUpdate()->first(); abort_unless($statement, 404);
            $this->scope->business($request, $statement->business_id, ['PLATFORM_ADMIN']);
            $transitions = ['DRAFT' => ['REVIEW'], 'REVIEW' => ['ISSUED'], 'ISSUED' => ['PAID','OVERDUE'], 'OVERDUE' => ['PAID'], 'PAID' => []];
            abort_unless(in_array($data['status'], $transitions[$statement->status] ?? [], true), 409);
            $update = ['status' => $data['status'], 'updated_at' => now()];
            if ($data['status'] === 'ISSUED') $update += ['issued_at' => now(), 'locked_at' => now()];
            if ($data['status'] === 'PAID') $update['paid_at'] = now();
            DB::table('platform_statements')->where('id', $id)->update($update);
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'business_id' => $statement->business_id, 'action' => 'STATUS_CHANGE', 'entity_type' => 'PlatformStatement', 'entity_id' => (string) $id, 'metadata' => json_encode(['from_status' => $statement->status, 'to_status' => $data['status'], 'reason' => $data['reason']], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            return DB::table('platform_statements')->find($id);
        }, 3);
    }
}
