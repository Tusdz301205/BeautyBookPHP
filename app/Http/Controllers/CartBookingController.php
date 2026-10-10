<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingConflictException;
use App\Models\Branch;
use App\Services\Booking\BookingAccessService;
use App\Services\Booking\CartBookingService;
use DomainException;
use Illuminate\Http\Request;

class CartBookingController extends Controller
{
    private function data(Request $request, string $action): array
    {
        if ($action === 'store') $request->merge(['idempotency_key' => $request->header('Idempotency-Key', $request->input('idempotency_key'))]);
        $rules = [
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*' => ['required', 'array:branch_service_offering_id,service_variant_id'],
            'items.*.branch_service_offering_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.service_variant_id' => ['nullable', 'integer', 'min:1'],
            'staff_profile_id' => ['nullable', 'integer', 'min:1'],
            'voucher_code' => ['nullable', 'string', 'max:64'],
            'loyalty_points' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
        ];
        if ($action === 'slots') $rules['date'] = ['required', 'date_format:Y-m-d'];
        if ($action === 'store') $rules += [
            'start_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'source' => ['nullable', 'in:WALK_IN,PHONE'],
            'note' => ['nullable', 'string', 'max:2000'],
            'contact' => ['nullable', 'array:full_name,phone,email'],
            'contact.full_name' => ['nullable', 'string', 'max:160'],
            'contact.phone' => ['nullable', 'string', 'max:32'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'violation_acknowledged' => ['nullable', function ($attribute, $value, $fail) { if (! is_bool($value)) $fail('Xác nhận cảnh báo phải là giá trị boolean rõ ràng.'); }],
            'idempotency_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
        return $request->validate($rules);
    }

    public function quote(Request $request, Branch $branch, BookingAccessService $access, CartBookingService $cart)
    {
        $context = $access->resolve($request, $branch);
        $data = $this->data($request, 'quote');
        try { return response()->json(['data' => $cart->quote($branch, $context, $data)]); }
        catch (DomainException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function slots(Request $request, Branch $branch, BookingAccessService $access, CartBookingService $cart)
    {
        $context = $access->resolve($request, $branch);
        $data = $this->data($request, 'slots');
        try { return response()->json($cart->slots($branch, $context, $data)); }
        catch (DomainException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function store(Request $request, Branch $branch, BookingAccessService $access, CartBookingService $cart)
    {
        $context = $access->resolve($request, $branch);
        $data = $this->data($request, 'store');
        try {
            $result = $cart->create($branch, $context, $data);
            $booking = $result['booking'];
            return response()->json(['booking' => $booking, 'booking_id' => $booking->id, 'booking_code' => $booking->booking_code,
                'status' => $booking->status, 'replayed' => $result['replayed'], 'redirect' => route('bookings.index', ['booking' => $booking->id]),
                'message' => $result['replayed'] ? 'Lịch hẹn đã được tạo trước đó.' : 'Đặt lịch thành công. Lịch đang chờ xác nhận.'], 201);
        } catch (BookingConflictException $exception) { return response()->json(['message' => $exception->getMessage()], 409); }
        catch (DomainException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }
}
