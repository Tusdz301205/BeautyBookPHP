<?php

namespace App\Http\Controllers\Beautybook;

use App\Enums\AccountKind;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\PaymentTransaction;
use App\Models\RefundRequest;
use App\Services\Beautybook\ApiScope;
use App\Services\Beautybook\BookingPresenter;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\FinancialPolicyService;
use App\Services\Finance\Money;
use App\Services\Finance\PackageService;
use App\Services\Finance\PaymentService;
use App\Services\Finance\SettlementService;
use App\Services\Identity\SessionContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceController extends Controller
{
    public function __construct(private PaymentService $payments, private PackageService $packages, private FinanceScope $scope, private SessionContext $session) {}

    private function dto($row): array
    {
        $out = [];
        foreach ((array) ($row instanceof Model ? $row->getAttributes() : $row) as $key => $value) {
            $name = Str::camel($key);
            if (is_string($value) && str_ends_with($key, '_at') && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value)) {
                $value = \Carbon\CarbonImmutable::parse($value, config('app.timezone'))->utc()->toISOString();
            }
            if ($key === 'id' || str_ends_with($key, '_id')) {
                $value = $value === null ? null : (string) $value;
            }if (str_ends_with($key, '_cents')) {
                $name = Str::camel(substr($key, 0, -6));
                $value = Money::decimal((int) $value);
            } $out[$name] = $value;
        }
        if (isset($out['total'])) {
            $out['totalPrice'] = $out['total'];
        }if (isset($out['createdAt'])) {
            $out['occurredAt'] = $out['createdAt'];
        }if (isset($out['net'])) {
            $out['netAmount'] = $out['net'];
        }

        return $out;
    }

    private function bookings(Request $r)
    {
        $query = app(BookingController::class)->query($r);
        if ($r->user()->account_kind !== AccountKind::Customer) {
            $grants = app(ApiScope::class)->assignments($r);
            abort_unless($grants->whereIn('role_name', ['BUSINESS_OWNER', 'RECEPTIONIST', 'PLATFORM_ADMIN'])->isNotEmpty(), 403);
            if (! $grants->whereIn('role_name', ['BUSINESS_OWNER', 'PLATFORM_ADMIN'])->isNotEmpty()) {
                $query->whereIn('branch_id', $grants->where('role_name', 'RECEPTIONIST')->pluck('branch_id'));
            }
        }

        return $query;
    }

    private function booking(Request $r, string $id): Booking
    {
        $q = $this->bookings($r);

        return ctype_digit($id) ? $q->findOrFail($id) : $q->where('booking_code', $id)->firstOrFail();
    }

    private function business(Request $r, array $roles = ['BUSINESS_OWNER', 'RECEPTIONIST', 'PLATFORM_ADMIN']): int
    {
        $a = app(ApiScope::class)->forRoles($r, $roles, $r->businessId ?: null);
        abort_unless($a && in_array($a->role_name, $roles, true), 403);
        $id = (int) ($r->businessId ?: $a->business_id);
        abort_unless($id || $a->role_name === 'PLATFORM_ADMIN', 422);
        if ($id) {
            $this->scope->business(app(ApiScope::class)->requestForRoles($r, $roles, $id, $a->branch_id), $id, $roles, $a->branch_id);
        }

        return $id;
    }

    private function operating(Request $r, Booking $booking, array $roles = ['BUSINESS_OWNER', 'RECEPTIONIST', 'PLATFORM_ADMIN']): Request
    {
        return $r->user()->account_kind === AccountKind::Customer ? $r : app(ApiScope::class)->requestForRoles($r, $roles, $booking->business_id, $booking->branch_id);
    }

    private function packageRequest(Request $r, object $record, array $roles = ['BUSINESS_OWNER', 'RECEPTIONIST', 'PLATFORM_ADMIN']): Request
    {
        return $r->user()->account_kind === AccountKind::Customer ? $r : app(ApiScope::class)->requestForRoles($r, $roles, $record->business_id, $record->branch_id ?? null);
    }

    public function index(Request $r)
    {
        $bookings = $this->bookings($r)->with('branch', 'customerProfile.user')->get();

        return $bookings->filter(fn ($b) => PaymentTransaction::where('booking_id', $b->id)->exists())->map(function ($b) {
            $tx = PaymentTransaction::where('booking_id', $b->id)->with('refunds')->get();
            $bal = $this->payments->balance($b);

            return ['id' => (string) $tx->first()->id, 'bookingId' => (string) $b->id, 'booking' => app(BookingPresenter::class)->detail($b), 'amount' => Money::decimal($bal['collected_cents']), 'netAmount' => Money::decimal($bal['net_cents']), 'refundedAmount' => Money::decimal($bal['refunded_cents']), 'method' => $tx->last()->method, 'status' => $bal['refunded_cents'] > 0 ? ($bal['net_cents'] <= 0 ? 'REFUNDED' : 'PARTIALLY_REFUNDED') : ($bal['due_cents'] === 0 ? 'PAID' : ($bal['net_cents'] > 0 ? 'PARTIALLY_PAID' : 'PENDING')), 'transactions' => $tx->map(fn ($t) => $this->dto($t)), 'refundRequests' => $tx->flatMap(fn ($t) => $t->refunds)->map(fn ($f) => $this->dto($f))];
        })->values();
    }

    public function providers()
    {
        return [['code' => 'CASH', 'name' => 'Tiền mặt', 'enabled' => true], ['code' => 'BANK_TRANSFER', 'name' => 'Chuyển khoản tại cơ sở', 'enabled' => true]];
    }

    public function checkout(Request $r, string $id)
    {
        $b = $this->booking($r, $id);
        $this->payments->access($this->operating($r, $b), $b);
        $bal = $this->payments->balance($b);

        return ['bookingId' => (string) $b->id, 'booking' => app(BookingPresenter::class)->detail($b), 'totalAmount' => Money::decimal($bal['total_cents']), 'paidAmount' => Money::decimal($bal['net_cents']), 'remainingAmount' => Money::decimal($bal['due_cents']), 'pendingAmount' => Money::decimal($bal['pending_cents']), 'transactions' => PaymentTransaction::where('booking_id', $b->id)->get()->map(fn ($t) => $this->dto($t))];
    }

    public function collect(Request $r)
    {
        $r->validate(['bookingId' => 'required|string|max:100', 'method' => 'required|in:CASH,BANK_TRANSFER', 'amount' => 'nullable|numeric|min:0.01', 'idempotencyKey' => 'required|string|max:200', 'evidence' => 'nullable|array']);
        $b = $this->booking($r, (string) $r->bookingId);
        $data = ['method' => $r->method, 'amount' => $r->filled('amount') ? (string) $r->amount : Money::decimal($this->payments->balance($b)['collectable_cents']), 'idempotency_key' => $r->idempotencyKey, 'evidence' => $r->evidence];

        return $this->dto($this->payments->collect($this->operating($r, $b, ['BUSINESS_OWNER', 'RECEPTIONIST']), $b, $data));
    }

    public function verify(Request $r, int $id)
    {
        $r->validate(['settlementReference' => 'required|string|max:200', 'evidence' => 'required|array|min:1']);
        $t = PaymentTransaction::findOrFail($id);
        $data = ['settlement_reference' => $r->settlementReference, 'evidence' => $r->evidence];

        return $this->dto($t->booking_id ? $this->payments->verify($this->operating($r, $this->booking($r, (string) $t->booking_id), ['BUSINESS_OWNER', 'RECEPTIONIST']), $t, $data) : $this->packages->verify($r, $t, $data));
    }

    public function reverse(Request $r, int $id)
    {
        $r->validate(['reason' => 'required|string|max:1000', 'idempotencyKey' => 'required|string|max:200']);
        $t = PaymentTransaction::findOrFail($id);
        $data = ['reason' => $r->reason, 'idempotency_key' => $r->idempotencyKey];

        return $this->dto($t->booking_id ? $this->payments->reverse($this->operating($r, $this->booking($r, (string) $t->booking_id)), $t, $data) : $this->packages->reverse($r, $t, $data));
    }

    public function refund(Request $r, int $id)
    {
        $r->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:1000', 'evidence' => 'nullable|array']);
        $payment = PaymentTransaction::findOrFail($id);
        $booking = $this->booking($r, (string) $payment->booking_id);
        $r = $this->operating($r, $booking, ['BUSINESS_OWNER', 'PLATFORM_ADMIN']);
        $this->payments->access($r, $booking);

        return DB::transaction(function () use ($r, $booking) {
            DB::table('businesses')->where('id', $booking->business_id)->lockForUpdate()->first();
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $balance = $this->payments->balance($booking);
            abort_unless($balance['collected_cents'] >= $balance['total_cents'] && $balance['net_cents'] > 0, 409, 'Chỉ lịch đã thanh toán mới được yêu cầu hoàn tiền.');
            $remaining = Money::cents((string) $r->amount);
            $requests = [];
            foreach (PaymentTransaction::where('booking_id', $booking->id)->where('status', 'VERIFIED')->orderBy('id')->lockForUpdate()->get() as $transaction) {
                $reserved = (int) $transaction->refunds()->whereIn('status', ['PENDING', 'APPROVED', 'PROCESSING', 'REFUNDED'])->sum('amount_cents');
                $amount = min($remaining, $transaction->amount_cents - $reserved);
                if ($amount <= 0) {
                    continue;
                }
                $requests[] = $this->dto($this->payments->requestRefund($r, $transaction, ['amount' => Money::decimal($amount), 'reason' => $r->reason, 'evidence' => $r->evidence]));
                $remaining -= $amount;
                if ($remaining === 0) {
                    break;
                }
            }
            abort_unless($remaining === 0, 422, 'Số tiền hoàn vượt số tiền còn được hoàn.');

            return $requests[0] + ['refundRequests' => $requests];
        }, 3);
    }

    public function review(Request $r, int $id)
    {
        $data = $r->validate(['approve' => 'required|boolean', 'note' => 'nullable|string|max:1000']);

        $refund = RefundRequest::findOrFail($id);

        return $this->dto($this->payments->reviewRefund($this->operating($r, $this->booking($r, (string) $refund->transaction->booking_id), ['BUSINESS_OWNER', 'PLATFORM_ADMIN']), $refund, $data));
    }

    public function process(Request $r, int $id)
    {
        $r->validate(['action' => 'required|in:START,CONFIRM,FAIL', 'settlementReference' => 'required_if:action,CONFIRM|nullable|string|max:200', 'failureReason' => 'required_if:action,FAIL|nullable|string|max:1000']);

        $refund = RefundRequest::findOrFail($id);

        return $this->dto($this->payments->processRefund($this->operating($r, $this->booking($r, (string) $refund->transaction->booking_id), ['PLATFORM_ADMIN']), $refund, ['action' => $r->action, 'settlement_reference' => $r->settlementReference, 'failure_reason' => $r->failureReason]));
    }

    public function policies(Request $r)
    {
        $id = $this->business($r, ['BUSINESS_OWNER', 'PLATFORM_ADMIN']);

        return DB::table('payment_policies')->where('business_id', $id)->orderByDesc('version')->get()->map(fn ($p) => $this->dto($p));
    }

    public function createPolicy(Request $r, FinancialPolicyService $s)
    {
        $r->validate(['businessId' => 'required|integer', 'name' => 'required|string|max:160', 'depositType' => 'required|in:NONE,FIXED,PERCENTAGE,FULL_PREPAYMENT', 'depositValue' => 'required|numeric|min:0', 'allowSplitPayment' => 'required|boolean', 'allowInstallments' => 'required|boolean', 'effectiveFrom' => 'required|date', 'effectiveTo' => 'nullable|date|after_or_equal:effectiveFrom', 'branchId' => 'nullable|integer', 'serviceId' => 'nullable|integer']);
        $id = $s->create(app(ApiScope::class)->requestForRoles($r, ['BUSINESS_OWNER'], $r->businessId, $r->branchId), (int) $r->businessId, ['name' => $r->name, 'deposit_type' => $r->depositType, 'deposit_value' => (string) $r->depositValue, 'allow_split_payment' => $r->boolean('allowSplitPayment'), 'allow_installments' => $r->boolean('allowInstallments'), 'effective_from' => $r->effectiveFrom, 'effective_to' => $r->effectiveTo, 'branch_id' => $r->branchId, 'business_service_id' => $r->serviceId]);

        return $this->dto(DB::table('payment_policies')->find($id));
    }

    public function ledger(Request $r)
    {
        $a = app(ApiScope::class)->forRoles($r, ['BUSINESS_OWNER', 'PLATFORM_ADMIN']);
        abort_unless($a && in_array($a->role_name, ['BUSINESS_OWNER', 'PLATFORM_ADMIN'], true), 403);
        $bookings = $this->bookings($r)->when($r->businessId, fn ($q) => $q->where('business_id', $r->businessId))->select('id');
        $purchases = DB::table('package_purchases')->when($a->role_name !== 'PLATFORM_ADMIN', fn ($q) => $q->where('business_id', $a->business_id))->when($r->businessId, fn ($q) => $q->where('business_id', $r->businessId))->select('id');

        return DB::table('payment_ledger_entries')->where(fn ($q) => $q->whereIn('booking_id', $bookings)->orWhereIn('package_purchase_id', $purchases))->orderByDesc('id')->get()->map(fn ($x) => $this->dto($x));
    }

    public function statements(Request $r)
    {
        $id = $this->business($r, ['BUSINESS_OWNER', 'PLATFORM_ADMIN']);

        return DB::table('platform_statements')->when($id, fn ($q) => $q->where('business_id', $id))->latest()->get()->map(fn ($p) => $this->dto($p));
    }

    public function generate(Request $r, SettlementService $s)
    {
        $r->validate(['businessId' => 'required|integer', 'periodStart' => 'required|date_format:Y-m-d', 'periodEnd' => 'required|date_format:Y-m-d|after_or_equal:periodStart']);

        return $this->dto($s->generate(app(ApiScope::class)->requestForRoles($r, ['PLATFORM_ADMIN']), (int) $r->businessId, ['period_start' => $r->periodStart, 'period_end' => $r->periodEnd]));
    }

    public function transition(Request $r, int $id, SettlementService $s)
    {
        $data = $r->validate(['status' => 'required|in:REVIEW,ISSUED,PAID,OVERDUE', 'reason' => 'required|string|max:1000']);

        return $this->dto($s->transition(app(ApiScope::class)->requestForRoles($r, ['PLATFORM_ADMIN']), $id, $data));
    }

    public function packageIndex(Request $r)
    {
        $id = $this->business($r);
        $grants = app(ApiScope::class)->assignments($r);

        return DB::table('treatment_packages')->when($id, fn ($q) => $q->where('business_id', $id))->when($grants->whereIn('role_name', ['BUSINESS_OWNER', 'PLATFORM_ADMIN'])->isEmpty(), fn ($q) => $q->where(fn ($q) => $q->whereIn('branch_id', $grants->where('role_name', 'RECEPTIONIST')->pluck('branch_id'))->orWhereNull('branch_id')))->get()->map(fn ($p) => $this->dto($p));
    }

    public function createPackage(Request $r)
    {
        $r->validate(['businessId' => 'required|integer', 'name' => 'required|string|max:160', 'description' => 'nullable|string|max:2000', 'branchId' => 'nullable|integer', 'totalPrice' => 'required|numeric|min:0.01', 'sessionCount' => 'required|integer|between:1,100', 'validityDays' => 'required|integer|between:1,3650']);

        return $this->dto($this->packages->create(app(ApiScope::class)->requestForRoles($r, ['BUSINESS_OWNER'], $r->businessId, $r->branchId), (int) $r->businessId, ['name' => $r->name, 'description' => $r->description, 'branch_id' => $r->branchId, 'total_price' => (string) $r->totalPrice, 'session_count' => $r->sessionCount, 'validity_days' => $r->validityDays]));
    }

    public function purchase(Request $r, int $id)
    {
        $r->validate(['customerId' => 'required|integer', 'branchId' => 'nullable|integer', 'installmentCount' => 'required|integer|between:1,12']);

        $package = DB::table('treatment_packages')->find($id);
        abort_unless($package, 404);
        $package->branch_id = $r->branchId ?: $package->branch_id;

        return $this->dto($this->packages->purchase($this->packageRequest($r, $package, ['BUSINESS_OWNER', 'RECEPTIONIST']), $id, ['customer_profile_id' => $r->customerId, 'branch_id' => $r->branchId, 'installment_count' => $r->installmentCount]));
    }

    public function purchases(Request $r)
    {
        $q = DB::table('package_purchases');
        if ($r->user()->account_kind === AccountKind::Customer) {
            $q->where('customer_profile_id', CustomerProfile::where('user_id', $r->user()->id)->value('id'));
        } else {
            $id = $this->business($r);
            $grants = app(ApiScope::class)->assignments($r);
            $q->when($id, fn ($q) => $q->where('business_id', $id))->when($grants->whereIn('role_name', ['BUSINESS_OWNER', 'PLATFORM_ADMIN'])->isEmpty(), fn ($q) => $q->whereIn('branch_id', $grants->where('role_name', 'RECEPTIONIST')->pluck('branch_id')));
        }

        return $q->get()->map(fn ($p) => $this->dto($p) + ['installments' => DB::table('package_installments')->where('package_purchase_id', $p->id)->orderBy('sequence')->get()->map(fn ($i) => $this->dto($i)), 'sessions' => DB::table('package_session_entitlements')->where('package_purchase_id', $p->id)->orderBy('sequence')->get()->map(fn ($i) => $this->dto($i))]);
    }

    public function installment(Request $r, int $id)
    {
        $r->validate(['method' => 'required|in:CASH,BANK_TRANSFER', 'idempotencyKey' => 'required|string|max:200', 'evidence' => 'nullable|array']);

        $installment = DB::table('package_installments')->find($id);
        abort_unless($installment, 404);
        $purchase = DB::table('package_purchases')->find($installment->package_purchase_id);
        abort_unless($purchase, 404);

        return $this->dto($this->packages->pay($this->packageRequest($r, $purchase), $id, ['method' => $r->method, 'idempotency_key' => $r->idempotencyKey, 'evidence' => $r->evidence]));
    }

    public function reserve(Request $r, int $id)
    {
        $r->validate(['bookingId' => 'required|integer', 'bookingServiceId' => 'required|integer']);

        $purchase = DB::table('package_purchases')->find($id);
        abort_unless($purchase, 404);

        return $this->dto($this->packages->reserve($this->packageRequest($r, $purchase, ['BUSINESS_OWNER', 'RECEPTIONIST']), $id, ['booking_id' => $r->bookingId, 'booking_service_id' => $r->bookingServiceId]));
    }
}
