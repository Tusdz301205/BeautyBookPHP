<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookingCreateRequest;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->get('status');
        $query = Booking::with(['branch.business', 'bookingServices.service', 'review'])
            ->where('customer_id', $request->user()->id);

        if ($status && in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'])) {
            $query->where('status', $status);
        }

        $bookings = $query->latest('appointment_date')->paginate(10)->withQueryString();

        return view('customer.bookings.index', compact('bookings', 'status'));
    }

    public function create(Request $request): View
    {
        $selectedServiceId = $request->get('service_id');
        $selectedBranchId = $request->get('branch_id');

        $selectedService = null;
        if ($selectedServiceId) {
            $selectedService = Service::with('branch.business')->find($selectedServiceId);
            if ($selectedService) {
                $selectedBranchId = $selectedService->branch_id;
            }
        }

        $branches = Branch::with(['business', 'district.province', 'services' => function ($q) {
            $q->where('status', 'active')->where('is_bookable', true);
        }, 'staff' => function ($q) {
            $q->where('status', 'active');
        }])
        ->where('status', 'active')
        ->whereHas('business', fn ($b) => $b->where('status', 'active'))
        ->get();

        return view('customer.bookings.create', compact(
            'branches',
            'selectedBranchId',
            'selectedService',
            'selectedServiceId'
        ));
    }

    public function store(BookingCreateRequest $request): RedirectResponse
    {
        $service = Service::with('branch')->findOrFail($request->service_id);

        if ($service->branch_id != $request->branch_id) {
            return back()->withInput()->withErrors(['service_id' => 'Dịch vụ không thuộc chi nhánh này.']);
        }

        if (!$service->is_bookable || $service->status !== 'active') {
            return back()->withInput()->withErrors(['service_id' => 'Dịch vụ này hiện không thể đặt lịch.']);
        }

        $finalPrice = $service->effective_price;
        $duration = $service->duration_minutes;

        $booking = DB::transaction(function () use ($request, $service, $finalPrice, $duration) {
            $code = 'BK-' . strtoupper(Str::random(8));

            $b = Booking::create([
                'booking_code' => $code,
                'customer_id' => $request->user()->id,
                'branch_id' => $request->branch_id,
                'appointment_date' => $request->appointment_date,
                'total_duration_minutes' => $duration,
                'total_price' => $finalPrice,
                'status' => 'pending',
                'notes' => $request->notes,
            ]);

            BookingService::create([
                'booking_id' => $b->id,
                'service_id' => $service->id,
                'staff_id' => $request->staff_id,
                'service_name' => $service->name,
                'price' => $finalPrice,
                'duration_minutes' => $duration,
            ]);

            BookingStatusHistory::create([
                'booking_id' => $b->id,
                'from_status' => null,
                'to_status' => 'pending',
                'changed_by' => $request->user()->id,
                'note' => 'Khách hàng tạo lịch hẹn trực tuyến',
            ]);

            return $b;
        });

        return redirect()->route('customer.bookings.show', $booking->id)
            ->with('success', 'Đặt lịch thành công! Mã lịch hẹn của bạn là: ' . $booking->booking_code);
    }

    public function show(Request $request, Booking $booking): View
    {
        // Ownership check
        if ($booking->customer_id !== $request->user()->id) {
            abort(403, 'Bạn không có quyền xem lịch hẹn này.');
        }

        $booking->load([
            'branch.business',
            'branch.district.province',
            'bookingServices.service',
            'bookingServices.staff',
            'statusHistories.changer',
            'review',
        ]);

        return view('customer.bookings.show', compact('booking'));
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse|JsonResponse
    {
        if ($booking->customer_id !== $request->user()->id) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Bạn không có quyền thao tác lịch hẹn này.'], 403);
            }
            abort(403);
        }

        if (!in_array($booking->status, ['pending', 'confirmed'])) {
            $msg = 'Lịch hẹn không ở trạng thái có thể hủy.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg], 422);
            }
            return back()->withErrors(['error' => $msg]);
        }

        $reason = $request->input('cancel_reason', 'Khách hàng hủy');

        DB::transaction(function () use ($booking, $request, $reason) {
            $fromStatus = $booking->status;

            $booking->update([
                'status' => 'cancelled',
                'cancelled_by' => 'customer',
                'cancel_reason' => $reason,
            ]);

            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'from_status' => $fromStatus,
                'to_status' => 'cancelled',
                'changed_by' => $request->user()->id,
                'note' => 'Hủy lịch bởi khách hàng: ' . $reason,
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Lịch hẹn đã được hủy thành công.',
                'new_status' => 'cancelled',
                'new_status_badge' => '<span class="px-2.5 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700">Đã hủy</span>',
            ]);
        }

        return back()->with('success', 'Lịch hẹn đã được hủy.');
    }
}
