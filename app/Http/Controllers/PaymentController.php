<?php

namespace App\Http\Controllers;

use App\Enums\AccountKind;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\PaymentLedgerEntry;
use App\Models\PaymentTransaction;
use App\Models\RefundRequest;
use App\Services\Finance\Money;
use App\Services\Finance\PaymentService;
use App\Services\Identity\SessionContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments, private SessionContext $context) {}

    public function index(Request $request)
    {
        $query = Booking::with('branch')->latest();
        if ($request->user()->account_kind === AccountKind::Customer) {
            $customer = CustomerProfile::where('user_id', $request->user()->id)->firstOrFail();
            $query->where('customer_profile_id', $customer->id);
        } else {
            $assignment = $this->context->current($request);
            abort_unless($assignment && in_array($assignment->role_name, ['BUSINESS_OWNER', 'RECEPTIONIST', 'PLATFORM_ADMIN'], true), 403);
            if ($assignment->role_name !== 'PLATFORM_ADMIN') {
                $query->where('business_id', $assignment->business_id);
                if ($assignment->branch_id) $query->where('branch_id', $assignment->branch_id);
            }
        }
        $filters = $request->validate(['search' => 'nullable|string|max:40']);
        if (! empty($filters['search'])) $query->where('booking_code', 'like', '%'.$filters['search'].'%');
        return view('finance.index', ['bookings' => $query->paginate(20)->withQueryString()]);
    }

    public function show(Request $request, Booking $booking)
    {
        $role = $this->payments->access($request, $booking);
        $transactions = PaymentTransaction::where('booking_id', $booking->id)->with('refunds', 'reversal')->latest()->get();
        $balance = $this->payments->balance($booking);
        if ($request->expectsJson()) return response()->json(['booking_id' => $booking->id, ...$balance, 'transactions' => $transactions]);
        return view('finance.show', [
            'booking' => $booking, 'role' => $role, 'transactions' => $transactions, 'balance' => $balance,
            'ledger' => PaymentLedgerEntry::where('booking_id', $booking->id)->latest('id')->get(),
        ]);
    }

    public function collect(Request $request, Booking $booking)
    {
        $data = $request->validate(['method' => 'required|in:CASH,BANK_TRANSFER', 'amount' => $this->amountRules(), 'idempotency_key' => 'required|string|max:200', 'evidence' => 'nullable|array']);
        $this->nonEmpty($data, ['idempotency_key']);
        return $this->respond($request, $booking->id, fn () => $this->payments->collect($request, $booking, $data), 201);
    }

    public function transactionAction(Request $request, PaymentTransaction $transaction, string $action)
    {
        abort_unless($transaction->booking_id,404);
        abort_unless(in_array($action, ['verify', 'reverse', 'refund'], true), 404);
        $rules = match ($action) {
            'verify' => ['settlement_reference' => 'required|string|max:200', 'evidence' => 'required|array|min:1'],
            'reverse' => ['reason' => 'required|string|max:1000', 'idempotency_key' => 'required|string|max:200'],
            default => ['amount' => $this->amountRules(), 'reason' => 'required|string|max:1000'],
        };
        $data = $request->validate($rules);
        $this->nonEmpty($data, ['settlement_reference', 'reason', 'idempotency_key']);
        if (isset($data['evidence']['receipt']) && trim($data['evidence']['receipt']) === '') abort(422);
        return $this->respond($request, $transaction->booking_id, fn () => match ($action) {
            'verify' => $this->payments->verify($request, $transaction, $data),
            'reverse' => $this->payments->reverse($request, $transaction, $data),
            default => $this->payments->requestRefund($request, $transaction, $data),
        });
    }

    public function refundAction(Request $request, RefundRequest $refund, string $action)
    {
        abort_unless($refund->transaction?->booking_id,404);
        abort_unless(in_array($action, ['review', 'process'], true), 404);
        $rules = $action === 'review' ? ['approve' => 'required|boolean', 'note' => 'nullable|string|max:1000'] : [
            'action' => 'required|in:START,CONFIRM,FAIL', 'settlement_reference' => 'required_if:action,CONFIRM|nullable|string|max:200',
            'failure_reason' => 'required_if:action,FAIL|nullable|string|max:1000',
        ];
        $data = $request->validate($rules);
        $this->nonEmpty($data, ['settlement_reference', 'failure_reason']);
        return $this->respond($request, $refund->transaction->booking_id, fn () => $action === 'review'
            ? $this->payments->reviewRefund($request, $refund, $data) : $this->payments->processRefund($request, $refund, $data));
    }

    private function respond(Request $request, int $bookingId, callable $callback, int $code = 200)
    {
        try { $record = $callback(); }
        catch (UniqueConstraintViolationException) { abort(409, 'Mã yêu cầu hoặc mã đối soát đã được sử dụng.'); }
        return $request->expectsJson() ? response()->json(['id' => $record->id, 'status' => $record->status, 'amount' => Money::decimal($record->amount_cents)], $code)
            : redirect()->route('finance.show', $bookingId)->with('status', 'Đã cập nhật thanh toán.');
    }
    private function amountRules(): array { return ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D']; }
    private function nonEmpty(array $data, array $keys): void
    {
        foreach ($keys as $key) if (isset($data[$key]) && trim($data[$key]) === '') abort(422);
    }
}
