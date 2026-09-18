<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $branchIds = $business ? $business->branches()->pluck('id') : collect();

        $query = Review::with(['customer', 'branch', 'booking.bookingServices.service'])
            ->whereIn('branch_id', $branchIds);

        if ($request->get('filter') === 'unreplied') {
            $query->whereNull('business_reply');
        }

        if ($request->filled('rating')) {
            $query->where('overall_rating', $request->rating);
        }

        $reviews = $query->latest()->paginate(10)->withQueryString();

        return view('owner.reviews.index', compact('reviews'));
    }

    public function reply(Request $request, Review $review): RedirectResponse|JsonResponse
    {
        if ($review->branch->business->owner_id !== $request->user()->id) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Bạn không có quyền phản hồi đánh giá này.'], 403);
            }
            abort(403);
        }

        $validated = $request->validate([
            'business_reply' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'business_reply.required' => 'Vui lòng nhập nội dung phản hồi.',
            'business_reply.min' => 'Nội dung phản hồi tối thiểu 3 ký tự.',
        ]);

        $review->update([
            'business_reply' => $validated['business_reply'],
            'replied_at' => Carbon::now(),
        ]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Đã gửi phản hồi đánh giá thành công!',
                'business_reply' => $review->business_reply,
                'replied_at' => $review->replied_at->format('d/m/Y H:i'),
            ]);
        }

        return back()->with('success', 'Đã gửi phản hồi đánh giá thành công!');
    }
}
