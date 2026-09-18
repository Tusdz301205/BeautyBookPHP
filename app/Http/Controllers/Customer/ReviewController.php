<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewCreateRequest;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $reviews = Review::with(['branch.business', 'booking.bookingServices.service'])
            ->where('customer_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return view('customer.reviews.index', compact('reviews'));
    }

    public function create(Request $request, Booking $booking): View|RedirectResponse
    {
        if ($booking->customer_id !== $request->user()->id) {
            abort(403, 'Bạn không có quyền đánh giá lịch hẹn này.');
        }

        if ($booking->status !== 'completed') {
            return redirect()->route('customer.bookings.show', $booking->id)
                ->withErrors(['error' => 'Chỉ có thể đánh giá sau khi đã hoàn thành dịch vụ.']);
        }

        if ($booking->review) {
            return redirect()->route('customer.bookings.show', $booking->id)
                ->withErrors(['error' => 'Bạn đã gửi đánh giá cho lịch hẹn này rồi.']);
        }

        $booking->load(['branch.business', 'bookingServices.service', 'bookingServices.staff']);

        return view('customer.reviews.create', compact('booking'));
    }

    public function store(ReviewCreateRequest $request): RedirectResponse
    {
        $booking = Booking::findOrFail($request->booking_id);

        if ($booking->customer_id !== $request->user()->id) {
            abort(403);
        }

        if ($booking->status !== 'completed') {
            return back()->withErrors(['error' => 'Chỉ có thể đánh giá sau khi hoàn thành dịch vụ.']);
        }

        if ($booking->review) {
            return back()->withErrors(['error' => 'Lịch hẹn này đã có đánh giá.']);
        }

        Review::create([
            'booking_id' => $booking->id,
            'customer_id' => $request->user()->id,
            'branch_id' => $booking->branch_id,
            'overall_rating' => $request->overall_rating,
            'comment' => $request->comment,
            'is_anonymous' => $request->boolean('is_anonymous'),
            'status' => 'approved',
        ]);

        return redirect()->route('customer.bookings.show', $booking->id)
            ->with('success', 'Cảm ơn bạn đã gửi đánh giá dịch vụ!');
    }
}
