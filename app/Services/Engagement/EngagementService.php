<?php

namespace App\Services\Engagement;

use App\Enums\AccountKind;
use App\Models\Booking;
use App\Models\BranchServiceOffering;
use App\Models\CustomerProfile;
use App\Models\Review;
use App\Models\RoleAssignment;
use App\Models\SavedService;
use App\Services\Booking\BookingNotificationService;
use App\Services\Identity\SessionContext;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EngagementService
{
    public function __construct(private SessionContext $context, private PlatformSettingsService $settings, private BookingNotificationService $notifications) {}

    public function customer(Request $request): CustomerProfile
    {
        abort_unless($request->user()?->account_kind === AccountKind::Customer, 403);
        return CustomerProfile::where('user_id', $request->user()->id)->firstOrFail();
    }

    public function management(Request $request, ?Booking $booking = null, bool $platform = false)
    {
        abort_unless($request->user()?->account_kind === AccountKind::Operational, 403);
        $assignment = $this->context->current($request);
        if ($assignment && DB::transactionLevel() > 0) $assignment = RoleAssignment::whereKey($assignment->id)->where('user_id',$request->user()->id)->lockForUpdate()->first();
        abort_unless($assignment && in_array($assignment->role_name, ['BUSINESS_OWNER', 'PLATFORM_ADMIN'], true), 403);
        if ($platform) abort_unless($assignment->role_name === 'PLATFORM_ADMIN', 403);
        if ($booking && $assignment->role_name !== 'PLATFORM_ADMIN') {
            abort_unless((int) $assignment->business_id === (int) $booking->business_id, 403);
            if ($assignment->branch_id) abort_unless((int) $assignment->branch_id === (int) $booking->branch_id, 403);
        }
        return $assignment;
    }

    public function create(Request $request, Booking $booking, array $data): Review
    {
        $customer = $this->customer($request);
        $policy = $this->settings->get();
        $comment = trim($data['comment'] ?? '');
        if (mb_strlen($comment) < $policy['reviewMinLength']) $this->invalid('Nội dung đánh giá chưa đủ độ dài theo quy định.');
        if (($data['is_anonymous'] ?? false) && ! $policy['allowAnonymousReview']) $this->invalid('Nền tảng hiện không cho phép đánh giá ẩn danh.');
        $data['comment'] = $comment;
        return DB::transaction(function () use ($customer, $booking, $data) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $booking->customer_profile_id === (int) $customer->id, 403);
            if ($booking->status !== 'COMPLETED') $this->invalid('Chỉ đánh giá lịch đã hoàn thành.');
            if (Review::withTrashed()->where('booking_id', $booking->id)->exists()) $this->invalid('Lịch này đã có đánh giá.');
            $ratings = [];
            foreach ($data['service_ratings'] ?? [] as $rating) {
                $item = $booking->services()->find($rating['booking_service_id']);
                if (! $item || isset($ratings[$item->id])) $this->invalid('Dịch vụ không thuộc lịch hoặc bị trùng.');
                if (isset($rating['staff_profile_id']) && (int) $rating['staff_profile_id'] !== (int) $item->staff_profile_id) $this->invalid('Nhân viên không khớp lịch.');
                $ratings[$item->id] = [...$rating, 'staff_profile_id' => $item->staff_profile_id, 'business_service_id' => $item->business_service_id, 'service_name' => $item->service_name_snapshot];
            }
            return Review::create([
                'booking_id' => $booking->id, 'customer_profile_id' => $customer->id,
                'overall_rating' => $data['overall_rating'], 'comment' => trim($data['comment']),
                'is_anonymous' => $data['is_anonymous'] ?? false, 'service_ratings' => array_values($ratings), 'status' => 'APPROVED',
            ]);
        });
    }

    public function mutate(Request $request, Review $review, string $action, array $data): void
    {
        DB::transaction(function () use ($request, $review, $action, $data) {
            $review = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            $from = $review->status;
            if ($action === 'reply') {
                $this->management($request, $review->booking);
                $review->update(['business_reply' => trim($data['content']), 'replied_by_user_id' => $request->user()->id, 'replied_at' => now()]);
                return;
            }
            if ($action === 'report') {
                if (DB::table('review_reports')->where('review_id', $review->id)->where('reporter_id', $request->user()->id)->exists()) $this->invalid('Bạn đã báo cáo đánh giá này.');
                $quarantine = in_array($data['severity'], ['HIGH', 'CRITICAL'], true) || in_array($data['category'], ['PII', 'THREAT', 'HATE', 'SEXUAL'], true);
                DB::table('review_reports')->insert(['review_id' => $review->id, 'reporter_id' => $request->user()->id, ...$data, 'created_at' => now(), 'updated_at' => now()]);
                $review->update(['status' => $from === 'HIDDEN' || $quarantine ? 'HIDDEN' : 'REPORTED']);
                $action = $quarantine ? 'QUARANTINE' : 'REPORT';
            } elseif ($action === 'appeal') {
                $own = $request->user()->account_kind === AccountKind::Customer && (int) $review->customerProfile->user_id === (int) $request->user()->id;
                if (! $own) $this->management($request, $review->booking);
                if (DB::table('review_appeals')->where('review_id', $review->id)->where('status', 'PENDING')->exists()) $this->invalid('Đã có khiếu nại đang chờ xử lý.');
                DB::table('review_appeals')->insert(['review_id' => $review->id, 'appellant_id' => $request->user()->id, 'reason' => trim($data['reason']), 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);
                $action = 'APPEAL_SUBMITTED';
            } elseif ($action === 'resolve') {
                $this->management($request, $review->booking, true);
                $appeal = DB::table('review_appeals')->where('review_id', $review->id)->where('id', $data['appeal_id'])->where('status', 'PENDING')->lockForUpdate()->first();
                if (! $appeal) $this->invalid('Khiếu nại không còn chờ xử lý.');
                DB::table('review_appeals')->where('id', $appeal->id)->update(['status' => $data['approve'] ? 'APPROVED' : 'REJECTED', 'resolution' => trim($data['reason']), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
                if ($data['approve']) $review->update(['status' => 'APPROVED']);
                $action = $data['approve'] ? 'APPEAL_APPROVED' : 'APPEAL_REJECTED';
            } else {
                $this->management($request, $review->booking, true);
                $review->update(['status' => $data['status']]);
                $action = $data['status'] === 'HIDDEN' ? 'HIDE' : ($from === 'HIDDEN' ? 'RESTORE' : 'APPROVE');
            }
            $eventId = DB::table('review_moderation_events')->insertGetId([
                'review_id' => $review->id, 'actor_id' => $request->user()->id, 'action' => $action,
                'from_status' => $from, 'to_status' => $review->status,
                'reason_code' => $data['reason_code'] ?? $data['category'] ?? 'APPEAL', 'reason' => trim($data['reason']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (in_array($action, ['REPORT', 'QUARANTINE', 'HIDE', 'RESTORE', 'APPROVE'], true)) {
                $recipients = [$review->customerProfile->user_id];
                if (in_array($action, ['REPORT', 'QUARANTINE'], true)) {
                    $recipients = array_merge($recipients, DB::table('role_assignments')->where('business_id', $review->booking->business_id)->where('role_name', 'BUSINESS_OWNER')->pluck('user_id')->all());
                }
                foreach (array_unique($recipients) as $recipient) {
                    $dedupe = 'review:'.$review->id.':event:'.$eventId.':user:'.$recipient;
                    $this->notifications->record((int) $recipient, 'SYSTEM', $review->status === 'HIDDEN' ? 'Đánh giá đã bị ẩn để kiểm duyệt' : 'Trạng thái đánh giá đã cập nhật', trim($data['reason']), null, $dedupe, $review->status === 'HIDDEN' ? 'WARNING' : 'INFO', ['review_id' => $review->id]);
                    DB::table('notifications')->where('dedupe_key', $dedupe)->update(['target_type' => 'Review', 'target_id' => (string) $review->id, 'action_url' => '/reviews']);
                }
            }
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id, 'business_id' => $review->booking->business_id,
                'action' => $action, 'entity_type' => 'Review', 'entity_id' => (string) $review->id,
                'metadata' => json_encode(['from_status' => $from, 'to_status' => $review->status, 'moderation_event_id' => $eventId], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        });
    }

    public function available(BranchServiceOffering $offering): bool
    {
        return ! $offering->trashed() && $offering->is_active && $offering->bookable
            && $offering->branch()->publicReady()->exists() && $offering->service()->exists();
    }

    public function save(Request $request, BranchServiceOffering $offering): void
    {
        $customer = $this->customer($request);
        abort_unless($this->available($offering), 404, 'Dịch vụ không còn khả dụng để lưu.');
        SavedService::firstOrCreate(['customer_profile_id' => $customer->id, 'branch_service_offering_id' => $offering->id]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['review' => $message]);
    }
}
