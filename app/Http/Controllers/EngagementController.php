<?php

namespace App\Http\Controllers;

use App\Enums\AccountKind;
use App\Models\Booking;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\StaffProfile;
use App\Models\Review;
use App\Models\SavedService;
use App\Services\Engagement\EngagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EngagementController extends Controller
{
    private array $publicStaffCache=[];
    public function __construct(private EngagementService $engagement) {}

    public function publicReviews(Request $request,Business $business)
    {
        $this->publicStaffCache=[];
        abort_unless($business->publicReady()->whereKey($business->id)->exists(), 404);
        $query = Review::where('status', 'APPROVED')->whereHas('booking', fn ($q) => $q->where('business_id', $business->id));
        $reviews=(clone $query)->with('booking.branch','customerProfile.user')->latest()->paginate(20);
        foreach($reviews as $review) {
            $review->public_service_ratings=$this->publicRatings($review);
            $review->public_avatar_url=$this->publicAvatar($review);
        }
        $average=$query->avg('overall_rating')??0;$total=$query->count();
        if($request->expectsJson())return response()->json([
            'data'=>$reviews->map(fn($review)=>[
                'id'=>$review->id,'overallRating'=>$review->overall_rating,'comment'=>$review->comment,'status'=>$review->status,'createdAt'=>$review->created_at,
                'customerName'=>$review->is_anonymous?'Ẩn danh':($review->customerProfile?->user?->name??'Ẩn danh'),'customerAvatar'=>$review->public_avatar_url,
                'branchName'=>$review->booking?->branch?->name,'appointmentDate'=>$review->booking?->appointment_start_at?->toDateString(),
                'serviceRatings'=>$review->public_service_ratings,'businessReply'=>$review->business_reply?['content'=>$review->business_reply,'updatedAt'=>$review->replied_at]:null,
            ]),
            'pagination'=>['page'=>$reviews->currentPage(),'limit'=>$reviews->perPage(),'total'=>$total,'totalPages'=>$reviews->lastPage()],
            'summary'=>['averageRating'=>(float)$average,'totalReviews'=>$total],
        ]);
        return view('engagement.reviews', ['reviews' => $reviews, 'average' => $average, 'total' => $total, 'business' => $business, 'mode' => 'public', 'bookings' => collect(), 'isAdmin' => false]);
    }

    public function staffSummary(StaffProfile $staff)
    {
        $this->publicStaffCache=[];
        abort_unless($this->publicStaff($staff->id),404);
        return $this->ratingSummary('staff_profile_id', $staff->id);
    }

    public function serviceSummary(BusinessService $service)
    {
        $this->publicStaffCache=[];
        return $this->ratingSummary('business_service_id', $service->id, 50);
    }

    private function ratingSummary(string $key, int $id, ?int $limit = null)
    {
        $ratings = collect();
        Review::where('status', 'APPROVED')->with('customerProfile.user')->latest()->chunk(200, function ($reviews) use (&$ratings, $key, $id) {
            foreach ($reviews as $review) {
                foreach ($review->service_ratings ?? [] as $rating) {
                    if ((int) ($rating[$key] ?? 0) === $id) $ratings->push([
                        'rating' => $rating['rating'], 'comment' => $rating['comment'] ?? null,
                        'serviceName' => $rating['service_name'], 'createdAt' => $review->created_at,
                        'customerName' => $review->is_anonymous ? 'Ẩn danh' : ($review->customerProfile?->user?->name ?? 'Ẩn danh'),
                        'customerAvatar'=>null,'staff'=>$this->publicStaff((int)($rating['staff_profile_id']??0)),
                    ]);
                }
            }
        });
        return response()->json(['averageRating' => round($ratings->avg('rating') ?? 0, 1), 'totalRatings' => $ratings->count(), 'ratings' => ($limit ? $ratings->take($limit) : $ratings)->values()]);
    }

    private function publicStaff(int $id): ?array
    {
        if(!$id)return null;
        if(!array_key_exists($id,$this->publicStaffCache)){
            $staff=StaffProfile::whereKey($id)->where('public_visible',true)->where('status','ACTIVE')->whereHas('branch',fn($q)=>$q->publicReady())->first();
            $this->publicStaffCache[$id]=$staff?['id'=>$staff->id,'fullName'=>$staff->full_name]:null;
        }
        return $this->publicStaffCache[$id];
    }
    private function publicRatings(Review $review): array
    {
        return array_map(function($rating){$staff=$this->publicStaff((int)($rating['staff_profile_id']??0));return [
            'serviceName'=>$rating['service_name'],'staffName'=>$staff['fullName']??null,'staff'=>$staff,'rating'=>$rating['rating'],'comment'=>$rating['comment']??null,
        ];},$review->service_ratings??[]);
    }

    public function index(Request $request)
    {
        $query = Review::with('booking.branch', 'customerProfile.user')->latest();
        $bookings = collect();
        $isAdmin = false;
        if ($request->user()->account_kind === AccountKind::Customer) {
            $customer = $this->engagement->customer($request);
            $query->where('customer_profile_id', $customer->id);
            $bookings = Booking::where('customer_profile_id', $customer->id)->where('status', 'COMPLETED')->whereNotIn('id', Review::withTrashed()->select('booking_id'))->with('services')->latest()->get();
            $mode = 'customer';
        } else {
            $assignment = $this->engagement->management($request);
            $isAdmin = $assignment->role_name === 'PLATFORM_ADMIN';
            if (! $isAdmin) $query->whereHas('booking', function ($q) use ($assignment) {
                $q->where('business_id', $assignment->business_id);
                if ($assignment->branch_id) $q->where('branch_id', $assignment->branch_id);
            });
            $mode = 'management';
        }
        $filter = $request->validate(['status' => 'nullable|in:APPROVED,REPORTED,HIDDEN', 'search' => 'nullable|string|max:100']);
        if (! empty($filter['status'])) $query->where('status', $filter['status']);
        if (! empty($filter['search'])) $query->where('comment', 'like', '%'.$filter['search'].'%');
        $reviews = $query->paginate(20)->withQueryString();
        $appeals = $isAdmin ? DB::table('review_appeals')->whereIn('review_id', $reviews->pluck('id'))->where('status', 'PENDING')->get()->groupBy('review_id') : collect();
        return view('engagement.reviews', compact('reviews', 'mode', 'bookings', 'isAdmin', 'appeals'));
    }

    public function store(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'overall_rating' => 'required|integer|between:1,5', 'comment' => 'nullable|string|max:5000', 'is_anonymous' => 'sometimes|boolean',
            'service_ratings' => 'sometimes|array|max:50', 'service_ratings.*.booking_service_id' => 'required|integer|distinct',
            'service_ratings.*.staff_profile_id' => 'nullable|integer', 'service_ratings.*.rating' => 'required|integer|between:1,5', 'service_ratings.*.comment' => 'nullable|string|max:2000',
        ]);
        $review = $this->engagement->create($request, $booking, $data);
        return $request->expectsJson() ? response()->json(['id' => $review->id, 'status' => $review->status], 201) : redirect()->route('engagement.reviews')->with('status', 'Đã gửi đánh giá.');
    }

    public function action(Request $request, Review $review, string $action)
    {
        abort_unless(in_array($action, ['reply', 'report', 'moderate', 'appeal', 'resolve'], true), 404);
        $rules = match ($action) {
            'reply' => ['content' => 'required|string|min:1|max:5000'],
            'report' => ['reason' => 'required|string|min:1|max:2000', 'category' => 'required|in:OTHER,SPAM,PII,THREAT,HATE,SEXUAL', 'severity' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL'],
            'moderate' => ['status' => 'required|in:APPROVED,HIDDEN', 'reason_code' => 'required|string|max:80', 'reason' => 'required|string|min:1|max:2000'],
            'resolve' => ['appeal_id' => 'required|integer', 'approve' => 'required|boolean', 'reason' => 'required|string|min:1|max:2000'],
            default => ['reason' => 'required|string|min:1|max:2000'],
        };
        $data = $request->validate($rules);
        // Whitespace-only input must not pass required string validation.
        foreach (['content', 'reason', 'reason_code'] as $key) if (isset($data[$key]) && trim($data[$key]) === '') abort(422);
        $this->engagement->mutate($request, $review, $action, $data);
        return $request->expectsJson() ? response()->json(['status' => $review->fresh()->status]) : back()->with('status', 'Đã cập nhật đánh giá.');
    }

    public function favorites(Request $request)
    {
        $customer = $this->engagement->customer($request);
        $saved = SavedService::where('customer_profile_id', $customer->id)->with('offering.service', 'offering.branch.business')->latest()->paginate(20);
        foreach ($saved as $item) $item->available = $item->offering && $this->engagement->available($item->offering);
        return view('engagement.favorites', compact('saved'));
    }

    public function save(Request $request, BranchServiceOffering $offering)
    {
        $this->engagement->save($request, $offering);
        return $request->expectsJson() ? response()->json(['saved' => true]) : back()->with('status', 'Đã lưu dịch vụ.');
    }

    public function remove(Request $request, int $offering)
    {
        $customer = $this->engagement->customer($request);
        SavedService::where('customer_profile_id', $customer->id)->where('branch_service_offering_id', $offering)->delete();
        return $request->expectsJson() ? response()->json(['removed' => true]) : back()->with('status', 'Đã bỏ lưu dịch vụ.');
    }

    private function publicAvatar(Review $review): ?string
    {
        $user = $review->customerProfile?->user;
        if ($review->is_anonymous || !$user?->is_active || !$user->avatar_media_file_id) return null;
        $media = \App\Models\MediaFile::whereKey($user->avatar_media_file_id)->where('uploaded_by', $user->id)
            ->where('entity_id', $user->id)->where('entity_type', 'CUSTOMER_AVATAR')->where('visibility', 'PUBLIC')
            ->whereNull('business_id')->whereNull('branch_id')->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->first();
        return $media ? route('profile.avatar', $media) : null;
    }
}
