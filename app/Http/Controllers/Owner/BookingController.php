<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $branchIds = $business ? $business->branches()->pluck('id') : collect();

        $query = Booking::with(['customer', 'branch', 'bookingServices.service', 'bookingServices.staff'])
            ->whereIn('branch_id', $branchIds);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('appointment_date', $request->date);
        }

        if ($request->filled('q')) {
            $keyword = trim($request->q);
            $query->where(function ($q) use ($keyword) {
                $q->where('booking_code', 'like', "%{$keyword}%")
                  ->orWhereHas('customer', function ($c) use ($keyword) {
                      $c->where('name', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%");
                  });
            });
        }

        $bookings = $query->latest('appointment_date')->paginate(15)->withQueryString();
        $branches = $business ? $business->branches : collect();

        return view('owner.bookings.index', compact('bookings', 'branches'));
    }

    public function show(Request $request, Booking $booking): View
    {
        if ($booking->branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $booking->load([
            'customer',
            'branch.district.province',
            'bookingServices.service',
            'bookingServices.staff',
            'statusHistories.changer',
            'review',
        ]);

        return view('owner.bookings.show', compact('booking'));
    }

    public function updateStatus(Request $request, Booking $booking): RedirectResponse|JsonResponse
    {
        if ($booking->branch->business->owner_id !== $request->user()->id) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Bạn không có quyền thao tác lịch hẹn này.'], 403);
            }
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:confirmed,in_progress,completed,cancelled,no_show'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = $validated['status'];
        $note = $validated['note'] ?? null;
        $fromStatus = $booking->status;

        DB::transaction(function () use ($booking, $newStatus, $note, $fromStatus, $request) {
            $updateData = ['status' => $newStatus];

            if ($newStatus === 'cancelled') {
                $updateData['cancelled_by'] = 'owner';
                $updateData['cancel_reason'] = $note ?? 'Hủy bởi chủ cơ sở';
            }

            $booking->update($updateData);

            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'from_status' => $fromStatus,
                'to_status' => $newStatus,
                'changed_by' => $request->user()->id,
                'note' => $note ?? "Cập nhật trạng thái thành {$newStatus}",
            ]);
        });

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Cập nhật trạng thái lịch hẹn thành công!',
                'new_status' => $newStatus,
                'new_status_badge' => $booking->status_badge,
            ]);
        }

        return back()->with('success', 'Trạng thái lịch hẹn đã được cập nhật!');
    }
}
