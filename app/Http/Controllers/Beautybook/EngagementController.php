<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BranchServiceOffering;
use App\Models\Review;
use App\Models\SavedService;
use App\Services\Beautybook\ApiScope;
use App\Services\Beautybook\PublicPresenter;
use App\Services\Engagement\EngagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EngagementController extends Controller
{
    public function __construct(private ApiScope $scope, private EngagementService $service, private PublicPresenter $presenter) {}

    public function notifications(Request $request)
    {
        $query = DB::table('notifications')->where('user_id', $request->user()->id);
        $state = strtolower((string) $request->input('state', 'all'));
        if ($request->boolean('unreadOnly') || $state === 'unread') $query->where('is_read', false);
        if ($state === 'read') $query->where('is_read', true);
        foreach (['type', 'severity'] as $key) if ($request->filled($key) && $request->input($key) !== 'ALL') $query->where($key, $request->input($key));
        if ($request->filled('search')) $query->where(fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%')->orWhere('body', 'like', '%'.$request->string('search').'%'));
        return $this->scope->paginate($query->orderByDesc('id'), $request, fn ($row) => ['id' => (string) $row->id, 'type' => $row->type, 'severity' => $row->severity, 'title' => $row->title, 'body' => $row->body, 'isRead' => (bool) $row->is_read, 'readAt' => $row->read_at, 'createdAt' => $row->created_at, 'actionUrl' => $this->actionUrl($row), 'targetType' => $row->target_type, 'targetId' => $row->target_id, 'metadata' => json_decode($row->metadata ?? '{}', true)]);
    }

    public function unread(Request $request) { return ['count' => DB::table('notifications')->where('user_id', $request->user()->id)->where('is_read', false)->count()]; }
    public function read(Request $request, string $id)
    {
        $query = DB::table('notifications')->where('user_id', $request->user()->id)->where('id', $id);
        abort_unless((clone $query)->exists(), 404);
        $query->where('is_read', false)->update(['is_read' => true, 'read_at' => now()]);
        return ['success' => true];
    }
    public function readAll(Request $request)
    {
        return ['updatedCount' => DB::table('notifications')->where('user_id', $request->user()->id)->where('is_read', false)->update(['is_read' => true, 'read_at' => now()])];
    }
    public function device(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:4096', 'platform' => 'required|in:WEB,ANDROID,IOS,web,android,ios']);
        $hash = hash('sha256', $data['token']);
        DB::transaction(function () use ($request, $data, $hash) {
            $existing = DB::table('beautybook_device_tokens')->where('token_hash', $hash)->lockForUpdate()->first();
            abort_if($existing && (string) $existing->user_id !== (string) $request->user()->id, 409, 'Thiết bị đang được liên kết với tài khoản khác.');
            DB::table('beautybook_device_tokens')->updateOrInsert(['token_hash' => $hash], ['user_id' => $request->user()->id, 'token' => Crypt::encryptString($data['token']), 'platform' => strtoupper($data['platform']), 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
        }, 3);
        return ['success' => true];
    }

    public function saved(Request $request)
    {
        $customer = $this->scope->customer($request);
        return SavedService::where('customer_profile_id', $customer->id)->with(['offering' => fn ($q) => $q->withTrashed()->with(['service' => fn ($service) => $service->withTrashed(), 'branch.business'])])->latest()->get()->map(function ($row) {
            $available = $row->offering && $this->service->available($row->offering);
            $offering = $row->offering?->service && $row->offering?->branch ? $this->presenter->service($row->offering) : null;
            return ['id' => (string) $row->id, 'branchServiceOfferingId' => (string) $row->branch_service_offering_id, 'isAvailable' => $available, 'available' => $available, 'service' => $offering, 'offering' => $offering, 'createdAt' => $row->created_at];
        });
    }
    public function save(Request $request, string $id) { $this->service->save($request, BranchServiceOffering::findOrFail($id)); return ['saved' => true]; }
    public function unsave(Request $request, string $id) { SavedService::where('customer_profile_id', $this->scope->customer($request)->id)->where('branch_service_offering_id', $id)->delete(); return ['removed' => true]; }

    public function reviews(Request $request)
    {
        $request = $this->scope->requestForRoles($request, ['PLATFORM_ADMIN', 'BUSINESS_OWNER']);
        $assignment = $this->service->management($request);
        $query = Review::with('booking.branch', 'customerProfile.user');
        if ($assignment->role_name !== 'PLATFORM_ADMIN') $query->whereHas('booking', fn ($q) => $q->where('business_id', $assignment->business_id));
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        if ($request->filled('search')) $query->where('comment', 'like', '%'.$request->string('search').'%');
        if ($request->filled('branchId')) { $this->scope->branch($request, $request->input('branchId')); $query->whereHas('booking', fn ($q) => $q->where('branch_id', $request->input('branchId'))); }
        return $query->latest()->orderByDesc('id')->get()->map(fn ($r) => [...$this->presenter->review($r, true), 'appeals' => DB::table('review_appeals')->where('review_id', $r->id)->get()->map(fn ($a) => ['id' => (string) $a->id, 'reason' => $a->reason, 'status' => $a->status, 'resolution' => $a->resolution, 'createdAt' => $a->created_at])->all()]);
    }
    public function createReview(Request $request)
    {
        $data = $request->validate(['bookingId' => 'required|integer', 'overallRating' => 'required|integer|between:1,5', 'comment' => 'nullable|string|max:5000', 'isAnonymous' => 'sometimes|boolean', 'serviceRatings' => 'sometimes|array|max:50', 'serviceRatings.*.bookingServiceId' => 'required|integer|distinct', 'serviceRatings.*.staffId' => 'nullable|integer', 'serviceRatings.*.rating' => 'required|integer|between:1,5', 'serviceRatings.*.comment' => 'nullable|string|max:2000']);
        $review = $this->service->create($request, Booking::findOrFail($data['bookingId']), ['overall_rating' => $data['overallRating'], 'comment' => $data['comment'] ?? '', 'is_anonymous' => $data['isAnonymous'] ?? false, 'service_ratings' => array_map(fn ($r) => ['booking_service_id' => $r['bookingServiceId'], 'staff_profile_id' => $r['staffId'] ?? null, 'rating' => $r['rating'], 'comment' => $r['comment'] ?? null], $data['serviceRatings'] ?? [])]);
        return response()->json($this->presenter->review($review), 201);
    }
    public function staffReviews(Request $request, string $id): array
    {
        $staff = \App\Models\StaffProfile::findOrFail($id);
        $assignment = $this->scope->forRoles($request, ['PLATFORM_ADMIN', 'BUSINESS_OWNER', 'STAFF'], $staff->business_id, $staff->branch_id);
        if ($assignment->role_name === 'STAFF') abort_unless((int) $staff->user_id === (int) $request->user()->id, 403);
        $this->scope->branch($request, $staff->branch_id);
        $ratings = collect();
        Review::where('status', 'APPROVED')->with('customerProfile.user')->latest()->chunk(200, function ($reviews) use ($staff, &$ratings) {
            foreach ($reviews as $review) foreach ($review->service_ratings ?? [] as $rating) {
                if ((int) ($rating['staff_profile_id'] ?? 0) !== $staff->id) continue;
                $ratings->push(['rating' => $rating['rating'], 'comment' => $rating['comment'] ?? null, 'serviceName' => $rating['service_name'] ?? null, 'customerName' => $review->is_anonymous ? 'Ẩn danh' : ($review->customerProfile?->user?->name ?? 'Ẩn danh'), 'createdAt' => $review->created_at?->toIso8601String()]);
            }
        });
        return ['averageRating' => round($ratings->avg('rating') ?? 0, 1), 'totalRatings' => $ratings->count(), 'ratings' => $ratings->all()];
    }
    public function reviewAction(Request $request, string $id, string $action)
    {
        $review = Review::findOrFail($id);
        if ($request->user()->account_kind === \App\Enums\AccountKind::Operational) $request = $this->scope->requestForRoles($request, ['PLATFORM_ADMIN', 'BUSINESS_OWNER'], $review->booking->business_id, $review->booking->branch_id);
        if ($action === 'report') $this->service->management($request, $review->booking);
        if ($request->has('reasonCode')) $request->merge(['reason_code' => $request->input('reasonCode')]);
        $request->mergeIfMissing(['category' => 'OTHER', 'severity' => 'LOW', 'reason_code' => 'MANUAL']);
        $data = $request->validate(match ($action) {
            'reply' => ['content' => 'required|string|min:1|max:5000'],
            'report' => ['reason' => 'required|string|min:1|max:2000', 'category' => 'required|in:OTHER,SPAM,ABUSE,PII,THREAT,HATE,SEXUAL', 'severity' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL'],
            'moderate' => ['status' => 'required|in:APPROVED,HIDDEN', 'reason_code' => 'required|string|max:80', 'reason' => 'required|string|min:1|max:2000'],
            'appeal' => ['reason' => 'required|string|min:1|max:2000'],
            default => abort(404),
        });
        $this->service->mutate($request, $review, $action, $data);
        return $this->presenter->review($review->fresh());
    }
    public function resolveAppeal(Request $request, string $id)
    {
        $this->scope->platform($request);
        if ($request->has('resolution')) $request->merge(['reason' => $request->input('resolution')]);
        $data = $request->validate(['status' => 'sometimes|in:APPROVED,REJECTED', 'approve' => 'sometimes|boolean', 'reason' => 'required|string|min:1|max:2000']);
        abort_unless(isset($data['approve']) || isset($data['status']), 422);
        $appeal = DB::table('review_appeals')->where('id', $id)->first(); abort_unless($appeal, 404);
        $this->service->mutate($request, Review::findOrFail($appeal->review_id), 'resolve', ['appeal_id' => (int) $id, 'approve' => $data['approve'] ?? $data['status'] === 'APPROVED', 'reason' => $data['reason']]);
        return ['success' => true];
    }

    private function actionUrl($row): ?string
    {
        if ($row->related_booking_id) {
            $path = $this->scopeRolePath($row->user_id);
            return $path === '/customer' ? $path.'/appointments/'.$row->related_booking_id : $path.'/appointments';
        }
        if ($row->target_type === 'Review') return $this->scopeRolePath($row->user_id).'/reviews';
        if ($row->type === 'OWNERSHIP_TRANSFER') return $this->scopeRolePath($row->user_id) === '/admin' ? '/admin/ownership' : '/salon/incoming-ownership';
        return $row->action_url;
    }
    private function scopeRolePath(int $userId): string {
        $assignment = $this->scope->current(request());
        return match ($assignment->role_name) { 'CUSTOMER' => '/customer', 'PLATFORM_ADMIN' => '/admin', default => '/salon' };
    }
}
