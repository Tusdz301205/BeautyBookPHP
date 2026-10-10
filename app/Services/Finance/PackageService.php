<?php

namespace App\Services\Finance;

use App\Enums\AccountKind;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomerProfile;
use App\Models\PaymentLedgerEntry;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackageService
{
    public function __construct(private FinanceScope $scope, private FinancialPolicyService $policies) {}

    public function create(Request $request, int $businessId, array $data): object
    {
        return DB::transaction(function()use($request,$businessId,$data){
            Business::whereKey($businessId)->lockForUpdate()->firstOrFail();
            $this->scope->business($request,$businessId,['BUSINESS_OWNER']);
            if (! empty($data['branch_id'])) abort_unless(Branch::whereKey($data['branch_id'])->where('business_id', $businessId)->exists(), 422);
            $total = Money::cents($data['total_price']); abort_unless($total > 0, 422);
            $id = DB::table('treatment_packages')->insertGetId(['business_id' => $businessId, 'branch_id' => $data['branch_id'] ?? null,
                'name' => trim($data['name']), 'description' => $data['description'] ?? null, 'total_cents' => $total,
                'session_count' => $data['session_count'], 'validity_days' => $data['validity_days'], 'created_at' => now(), 'updated_at' => now()]);
            return DB::table('treatment_packages')->find($id);
        },3);
    }

    public function purchase(Request $request, int $id, array $data): object
    {
        return DB::transaction(function () use ($request, $id, $data) {
            $package = DB::table('treatment_packages')->find($id); abort_unless($package && $package->status === 'ACTIVE', 404);
            Business::whereKey($package->business_id)->lockForUpdate()->firstOrFail();
            $branchId = (int) ($data['branch_id'] ?? $package->branch_id);
            abort_unless($branchId && Branch::whereKey($branchId)->where('business_id', $package->business_id)->exists() && (! $package->branch_id || (int) $package->branch_id === $branchId), 422);
            $this->scope->business($request, $package->business_id, ['BUSINESS_OWNER','RECEPTIONIST'], $branchId);
            CustomerProfile::findOrFail($data['customer_profile_id']);
            $policy = $this->policies->effective($package->business_id, $branchId);
            $count = (int) $data['installment_count'];
            abort_if($count > 1 && ! ($policy?->allow_installments ?? false), 409, 'Chính sách không cho phép trả góp.');
            abort_if($package->total_cents < $count, 422, 'Số kỳ tạo khoản phải thu nhỏ hơn một xu.');
            $base = intdiv($package->total_cents, $count);
            $id = DB::table('package_purchases')->insertGetId(['treatment_package_id' => $package->id, 'customer_profile_id' => $data['customer_profile_id'],
                'business_id' => $package->business_id, 'branch_id' => $branchId, 'total_cents' => $package->total_cents, 'expires_at' => now()->addDays($package->validity_days),
                'pricing_snapshot' => json_encode(['package_id' => $package->id, 'name' => $package->name, 'total_cents' => $package->total_cents,
                    'session_count' => $package->session_count, 'validity_days' => $package->validity_days, 'policy_id' => $policy?->id,
                    'policy_version' => $policy?->version, 'installment_count' => $count], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            for ($i = 1; $i <= $count; $i++) DB::table('package_installments')->insert(['package_purchase_id' => $id, 'sequence' => $i,
                'amount_cents' => $i === $count ? $package->total_cents - $base * ($count - 1) : $base, 'due_at' => now()->addDays(($i - 1) * 30), 'created_at' => now(), 'updated_at' => now()]);
            for ($i = 1; $i <= $package->session_count; $i++) DB::table('package_session_entitlements')->insert(['package_purchase_id' => $id, 'sequence' => $i, 'created_at' => now(), 'updated_at' => now()]);
            return DB::table('package_purchases')->find($id);
        }, 3);
    }

    public function pay(Request $request, int $installmentId, array $data): PaymentTransaction
    {
        return DB::transaction(function () use ($request, $installmentId, $data) {
            $reference = DB::table('package_installments')->find($installmentId); abort_unless($reference, 404);
            $purchase = $this->lockPurchase($request,$reference->package_purchase_id,['BUSINESS_OWNER','RECEPTIONIST']);
            $installment = DB::table('package_installments')->where('id', $installmentId)->lockForUpdate()->first();
            $previous = PaymentTransaction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($previous) { abort_unless((int) $previous->package_installment_id === $installmentId && $previous->method === $data['method'] && ! $previous->reversal_of_id, 409); return $previous; }
            abort_unless(in_array($installment->status, ['DUE','FAILED'], true), 409, 'Kỳ thu tiền không còn khả dụng.');
            $verified = $data['method'] === 'CASH';
            $transaction = PaymentTransaction::create(['package_purchase_id' => $purchase->id, 'package_installment_id' => $installmentId,
                'amount_cents' => $installment->amount_cents, 'currency' => $purchase->currency, 'method' => $data['method'], 'status' => $verified ? 'VERIFIED' : 'PENDING',
                'idempotency_key' => $data['idempotency_key'], 'evidence' => $data['evidence'] ?? null, 'created_by' => $request->user()->id,
                'verified_by' => $verified ? $request->user()->id : null, 'verified_at' => $verified ? now() : null]);
            DB::table('package_installments')->where('id', $installmentId)->update(['status' => $verified ? 'PAID' : 'PENDING', 'paid_at' => $verified ? now() : null, 'updated_at' => now()]);
            if ($verified) $this->postLedger($request, $transaction, 'PAYMENT_RECEIVED');
            $this->recalculate($purchase->id);
            return $transaction;
        }, 3);
    }

    public function verify(Request $request, PaymentTransaction $transaction, array $data): PaymentTransaction
    {
        return DB::transaction(function () use ($request, $transaction, $data) {
            abort_unless($transaction->package_purchase_id, 404);
            $purchase = $this->lockPurchase($request,$transaction->package_purchase_id,['BUSINESS_OWNER','RECEPTIONIST']);
            $transaction = $transaction->fresh(); abort_unless($transaction->method === 'BANK_TRANSFER', 422);
            if ($transaction->status === 'VERIFIED') { abort_unless($transaction->settlement_reference === trim($data['settlement_reference']), 409); return $transaction; }
            abort_unless($transaction->status === 'PENDING', 409);
            $transaction->update(['status' => 'VERIFIED', 'settlement_reference' => trim($data['settlement_reference']), 'evidence' => $data['evidence'], 'verified_by' => $request->user()->id, 'verified_at' => now()]);
            DB::table('package_installments')->where('id', $transaction->package_installment_id)->update(['status' => 'PAID', 'paid_at' => now(), 'updated_at' => now()]);
            $this->postLedger($request, $transaction, 'PAYMENT_RECEIVED'); $this->recalculate($purchase->id);
            return $transaction;
        }, 3);
    }

    public function reverse(Request $request, PaymentTransaction $transaction, array $data): PaymentTransaction
    {
        return DB::transaction(function () use ($request, $transaction, $data) {
            abort_unless($transaction->package_purchase_id, 404);
            $purchase = $this->lockPurchase($request,$transaction->package_purchase_id,['BUSINESS_OWNER','RECEPTIONIST','PLATFORM_ADMIN']);
            $transaction = $transaction->fresh(); abort_unless($transaction->status === 'VERIFIED', 409);
            $existing = PaymentTransaction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) { abort_unless((int) $existing->reversal_of_id === (int) $transaction->id && ($existing->evidence['reason'] ?? null) === trim($data['reason']), 409); return $existing; }
            abort_if($transaction->reversal()->exists(), 409);
            $reversal = PaymentTransaction::create(['package_purchase_id' => $purchase->id, 'package_installment_id' => $transaction->package_installment_id,
                'amount_cents' => $transaction->amount_cents, 'currency' => $purchase->currency, 'method' => $transaction->method, 'status' => 'REVERSED',
                'idempotency_key' => $data['idempotency_key'], 'reversal_of_id' => $transaction->id, 'evidence' => ['reason' => trim($data['reason'])],
                'created_by' => $request->user()->id, 'verified_by' => $request->user()->id, 'verified_at' => now()]);
            DB::table('package_installments')->where('id', $transaction->package_installment_id)->update(['status' => 'DUE', 'paid_at' => null, 'updated_at' => now()]);
            $this->postLedger($request, $reversal, 'REVERSAL'); $this->recalculate($purchase->id);
            return $reversal;
        }, 3);
    }

    public function reserve(Request $request, int $purchaseId, array $data): object
    {
        return DB::transaction(function () use ($request, $purchaseId, $data) {
            $reference = DB::table('package_purchases')->find($purchaseId); abort_unless($reference, 404);
            Business::whereKey($reference->business_id)->lockForUpdate()->firstOrFail();
            $booking = Booking::whereKey($data['booking_id'])->lockForUpdate()->firstOrFail();
            $this->readAccess($request,$reference,true);
            $purchase = DB::table('package_purchases')->where('id', $purchaseId)->lockForUpdate()->first();
            abort_unless($purchase->status === 'ACTIVE' && \Carbon\Carbon::parse($purchase->expires_at)->isFuture(), 409);
            abort_unless((int) $booking->customer_profile_id === (int) $purchase->customer_profile_id && (int) $booking->business_id === (int) $purchase->business_id && (int) $booking->branch_id === (int) $purchase->branch_id, 422);
            abort_unless(! in_array($booking->status, ['COMPLETED','CANCELLED','NO_SHOW','REJECTED','EXPIRED'], true), 409);
            abort_unless(BookingService::whereKey($data['booking_service_id'])->where('booking_id', $booking->id)->exists(), 422);
            abort_if(DB::table('package_session_entitlements')->where('booking_service_id', $data['booking_service_id'])->exists(), 409);
            $entitlement = DB::table('package_session_entitlements')->where('package_purchase_id', $purchaseId)->where('status','AVAILABLE')->orderBy('sequence')->lockForUpdate()->first(); abort_unless($entitlement, 409, 'Không còn lượt sử dụng.');
            DB::table('package_session_entitlements')->where('id', $entitlement->id)->update(['status' => 'RESERVED', 'booking_id' => $booking->id, 'booking_service_id' => $data['booking_service_id'], 'reserved_at' => now(), 'updated_at' => now()]);
            return DB::table('package_session_entitlements')->find($entitlement->id);
        }, 3);
    }

    public function redeemForBooking(int $bookingId, string $status): void
    {
        if (! in_array($status, ['COMPLETED','CANCELLED','NO_SHOW','EXPIRED','REJECTED'], true)) return;
        $rows = DB::table('package_session_entitlements')->where('booking_id', $bookingId)->where('status','RESERVED')->get();
        foreach ($rows->pluck('package_purchase_id')->unique()->sort() as $purchaseId) {
            DB::table('package_purchases')->where('id', $purchaseId)->lockForUpdate()->first();
            $update = $status === 'COMPLETED' ? ['status' => 'REDEEMED', 'redeemed_at' => now()] : ['status' => 'AVAILABLE', 'booking_id' => null, 'booking_service_id' => null, 'reserved_at' => null];
            DB::table('package_session_entitlements')->where('booking_id', $bookingId)->where('package_purchase_id', $purchaseId)->where('status','RESERVED')->update([...$update, 'updated_at' => now()]);
            $this->recalculate($purchaseId);
        }
    }

    public function readAccess(Request $request, object $purchase, bool $reserve = false): void
    {
        if ($request->user()?->account_kind === AccountKind::Customer) {
            abort_unless(CustomerProfile::whereKey($purchase->customer_profile_id)->where('user_id', $request->user()->id)->exists(), 403);
        } else $this->scope->business($request, $purchase->business_id, $reserve ? ['BUSINESS_OWNER','RECEPTIONIST'] : ['BUSINESS_OWNER','RECEPTIONIST','PLATFORM_ADMIN'], $purchase->branch_id);
    }

    private function lockPurchase(Request $request,int $id,array $roles): object
    {
        $reference = DB::table('package_purchases')->find($id); abort_unless($reference, 404);
        Business::whereKey($reference->business_id)->lockForUpdate()->firstOrFail();
        $this->scope->business($request,$reference->business_id,$roles,$reference->branch_id);
        return DB::table('package_purchases')->where('id', $id)->lockForUpdate()->first();
    }
    private function recalculate(int $id): void
    {
        $purchase = DB::table('package_purchases')->find($id);
        $paid = (int) PaymentTransaction::where('package_purchase_id', $id)->where('status','VERIFIED')->sum('amount_cents') - (int) PaymentTransaction::where('package_purchase_id', $id)->where('status','REVERSED')->sum('amount_cents');
        $allRedeemed = ! DB::table('package_session_entitlements')->where('package_purchase_id', $id)->where('status','!=','REDEEMED')->exists();
        DB::table('package_purchases')->where('id', $id)->update(['paid_cents' => max(0, $paid), 'status' => $allRedeemed && $paid >= $purchase->total_cents ? 'COMPLETED' : ($paid > 0 ? 'ACTIVE' : 'PENDING_PAYMENT'), 'updated_at' => now()]);
    }
    private function postLedger(Request $request, PaymentTransaction $transaction, string $type): void
    {
        PaymentLedgerEntry::firstOrCreate(['idempotency_key' => 'ledger:'.$type.':'.$transaction->id], ['package_purchase_id' => $transaction->package_purchase_id, 'payment_transaction_id' => $transaction->id,
            'type' => $type, 'direction' => $type === 'REVERSAL' ? 'DEBIT' : 'CREDIT', 'amount_cents' => $transaction->amount_cents, 'actor_id' => $request->user()->id, 'created_at' => now()]);
    }
}
