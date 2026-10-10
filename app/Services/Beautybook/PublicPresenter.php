<?php

namespace App\Services\Beautybook;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\MediaFile;
use App\Models\Review;
use App\Models\StaffProfile;

class PublicPresenter
{
    public function __construct(private SourceReferenceMedia $referenceMedia) {}

    public function images(string $type, mixed $id): array
    {
        return MediaFile::where('entity_type', $type)->where('entity_id', $id)->where('visibility', 'PUBLIC')->where('mime_type', 'like', 'image/%')->orderBy('id')->get()->map(fn ($image) => ['id' => (string) $image->id, 'url' => route('media.show', $image), 'media' => ['id' => (string) $image->id, 'url' => route('media.show', $image)], 'alt' => $image->original_name])->all();
    }

    public function branch(Branch $branch, bool $detail = false): array
    {
        $offerings = $branch->offerings()->where('is_active', true)->where('bookable', true)->whereHas('service', fn ($q) => $q->where('is_active', true)->where('status', 'ACTIVE'))->with('service.category', 'variants')->get();
        $reviews = Review::where('status', 'APPROVED')->whereHas('booking', fn ($q) => $q->where('branch_id', $branch->id));
        $rating = round((float) (clone $reviews)->avg('overall_rating'), 1);
        $categories = $offerings->map(fn ($o) => $o->service?->category)->filter()->unique('id')->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name])->values()->all();
        $images = $this->images('BRANCH', $branch->id);
        $hours = $branch->workingHours->map(fn ($h) => ['id' => (string) $h->id, 'dayOfWeek' => $h->day_of_week, 'openTime' => $h->open_time, 'closeTime' => $h->close_time, 'isClosed' => $h->is_closed])->all();
        $data = ['id' => (string) $branch->id, 'businessId' => (string) $branch->business_id, 'name' => $branch->public_name ?: $branch->name, 'branch_name' => $branch->name, 'slug' => $branch->slug,
            'address' => $branch->address_line, 'addressLine' => $branch->address_line, 'phone' => $branch->phone, 'email' => $branch->email, 'description' => $branch->description,
            'business' => ['id' => (string) $branch->business_id, 'name' => $branch->business->name, 'description' => $branch->business->description, 'slug' => $branch->business->slug],
            'categories' => $categories, 'rating' => $rating, 'reviewCount' => (clone $reviews)->count(), 'minPrice' => (float) ($offerings->min('price') ?? 0),
            'services' => $detail ? $offerings->map(fn ($o) => $this->service($o))->all() : $offerings->count(),
            'images' => $images, 'coverImage' => $images[0]['url'] ?? null, 'workingHours' => $detail ? $hours : ['summary' => $this->hoursSummary($hours)],
            'status' => $branch->status?->value ?? (string) $branch->status, 'reviewStatus' => $branch->review_status?->value ?? (string) $branch->review_status,
            'operationalStatus' => $branch->operational_status?->value ?? (string) $branch->operational_status,
        ];
        $data['generatedMedia'] = $this->referenceMedia->for('BRANCH', $branch->id);
        $data['business']['generatedMedia'] = $this->referenceMedia->for('BUSINESS', $branch->business_id);
        $location = $this->district($branch->id);
        $data['district'] = $detail ? $location : ($location['name'] ?? null);
        $data['provinceName'] = $location['province']['name'] ?? null;
        if ($detail) {
            $data['staff'] = $branch->staffProfiles()->publicBookable()->get()->map(fn ($s) => $this->staff($s))->all();
            $data['stats'] = ['averageRating' => $rating, 'totalReviews' => (clone $reviews)->count(), 'totalBookings' => $branch->bookings()->count()];
            $data['recentReviews'] = (clone $reviews)->with('customerProfile.user', 'booking.branch')->latest()->limit(10)->get()->map(fn ($r) => $this->review($r))->all();
        }
        return $data;
    }

    public function service(BranchServiceOffering $offering, bool $detail = false): array
    {
        $service = $offering->service;
        $branch = $offering->branch;
        $staff = $offering->staff()->publicBookable()->get();
        $variants = $offering->variants()->where('status', 'ACTIVE')->get();
        $canonical = $service->canonicalService;
        $resolved = $canonical?->status === 'MERGED' ? $canonical->replacement : $canonical;
        $reviews = Review::where('status', 'APPROVED')->whereHas('booking.services', fn ($q) => $q->where('branch_service_offering_id', $offering->id));
        $images = $this->images('SERVICE', $offering->id);
        $data = ['id' => (string) $offering->id, 'branchServiceOfferingId' => (string) $offering->id, 'businessServiceId' => (string) $service->id, 'branchId' => (string) $offering->branch_id, 'businessId' => (string) $offering->business_id,
            'name' => $service->name, 'displayName' => $service->name, 'description' => $service->description, 'price' => (float) $offering->price, 'durationMinutes' => $offering->duration_minutes,
            'categoryId' => $service->service_category_id ? (string) $service->service_category_id : null, 'categoryName' => $service->category?->name,
            'category' => $service->category ? ['id' => (string) $service->category->id, 'name' => $service->category->name, 'slug' => $service->category->slug] : null,
            'rating' => round((float) ($offering->public_rating ?? 0), 1), 'bookingCount' => (int) ($offering->booking_count ?? 0),
            'canonicalServiceName' => $service->canonicalService?->name, 'businessName' => $branch->business->name, 'branchName' => $branch->public_name ?: $branch->name,
            'address' => $branch->address_line, 'images' => $images, 'imageUrl' => $images[0]['url'] ?? null, 'availableStaffCount' => $staff->count(),
            'isActive' => $offering->is_active, 'isBookable' => $offering->bookable, 'bookable' => $offering->bookable,
            'variants' => $offering->variants()->where('status', 'ACTIVE')->get()->map(fn ($v) => $this->variant($v))->all(),
            'branch' => ['id' => (string) $branch->id, 'businessId' => (string) $branch->business_id, 'name' => $branch->public_name ?: $branch->name, 'addressLine' => $branch->address_line, 'business' => ['id' => (string) $branch->business_id, 'name' => $branch->business->name]],
        ];
        $data['generatedMedia'] = $this->referenceMedia->for('SERVICE', $offering->id);
        $data['duration'] = $offering->duration_minutes;
        $data['status'] = $offering->is_active ? 'ACTIVE' : 'INACTIVE';
        $data['active'] = $offering->is_active && $offering->bookable;
        $data['mappingStatus'] = $service->mapping_status;
        $data['canonicalServiceId'] = $resolved ? (string) $resolved->id : null;
        $data['canonicalServiceName'] = $resolved?->name;
        $data['canonicalService'] = $canonical ? ['id' => (string) $canonical->id, 'name' => $canonical->name, 'slug' => $canonical->slug, 'status' => $canonical->status] : null;
        $data['reviewCount'] = (clone $reviews)->count();
        $data['rating'] = round((float) ($offering->public_rating ?? (clone $reviews)->avg('overall_rating') ?? 0), 1);
        $data['searchRank'] = (int) ($offering->search_rank ?? 0);
        $data['availabilitySummary'] = $staff->count() ? $staff->count().' chuyên viên đang nhận lịch' : 'Chưa có lịch khả dụng';
        $data['stylists'] = $staff->pluck('full_name')->all();
        $data['priceDisplay'] = $this->priceDisplay($variants, $offering->price);
        $location = $this->district($branch->id);
        $data['districtName'] = $location['name'] ?? null;
        $data['provinceName'] = $location['province']['name'] ?? null;
        $data['branch']['district'] = $location;
        $reference = $this->referenceMedia->reference('SERVICE', $offering->id);
        if (isset($reference['description'])) $data['description'] = $reference['description'];
        if ($detail) {
            $data['staffServices'] = $staff->map(fn ($s) => ['staff' => $this->staff($s)])->all();
            $data['promotionLinks'] = \App\Models\Promotion::where('status', 'ACTIVE')->where('start_date', '<=', now())->where('end_date', '>=', now())
                ->where(fn ($q) => $q->where('business_id', $offering->business_id)->orWhere(fn ($platform) => $platform->whereNull('business_id')->where('created_by_platform', true)))
                ->get()->filter(fn ($promotion) => in_array((string) $offering->id, array_map('strval', $promotion->service_ids ?? []), true))
                ->map(fn ($promotion) => ['promotion' => ['id' => (string) $promotion->id, 'name' => $promotion->name, 'description' => $promotion->description, 'discountType' => $promotion->discount_type, 'discountValue' => (float) $promotion->discount_value, 'startDate' => $promotion->start_date?->toIso8601String(), 'endDate' => $promotion->end_date?->toIso8601String()]])->values()->all();
        }
        return $data;
    }

    public function variant($v): array
    {
        $money = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').'đ';
        $display = match ($v->price_type) {
            'QUOTE' => 'Cần tư vấn',
            'FROM' => 'Từ '.$money($v->price),
            'RANGE' => $money($v->price).' – '.$money($v->max_price),
            default => $money($v->price),
        };
        return ['id' => (string) $v->id, 'code' => $v->code, 'name' => $v->name, 'description' => $v->description, 'priceType' => $v->price_type, 'price' => $v->price === null ? null : (float) $v->price, 'priceDisplay' => $display, 'maxPrice' => $v->max_price === null ? null : (float) $v->max_price, 'durationMinutes' => $v->duration_minutes, 'maxDurationMinutes' => $v->max_duration_minutes, 'bufferBeforeMinutes' => $v->buffer_before_minutes, 'bufferAfterMinutes' => $v->buffer_after_minutes, 'consultationRequired' => $v->consultation_required, 'eligibilityRules' => $v->eligibility_rules, 'status' => $v->status, 'version' => $v->version];
    }

    private function priceDisplay($variants, $fallback): string
    {
        $money = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').'đ';
        if ($variants->isEmpty()) return $money($fallback);
        if ($variants->contains('price_type', 'QUOTE')) return 'Có lựa chọn cần tư vấn';
        $prices = $variants->flatMap(fn ($variant) => [$variant->price, $variant->max_price])->filter(fn ($value) => $value !== null)->map(fn ($value) => (float) $value);
        if ($prices->isEmpty()) return 'Cần tư vấn';
        return $prices->min() === $prices->max() ? $money($prices->min()) : 'Từ '.$money($prices->min()).' – '.$money($prices->max());
    }

    public function staff(StaffProfile $staff, bool $detail = false): array
    {
        $images = $this->images('STAFF', $staff->id);
        $ratings = [];
        Review::where('status', 'APPROVED')->whereHas('booking', fn ($q) => $q->where('business_id', $staff->business_id))->get()->each(function ($review) use ($staff, &$ratings) {
            foreach ($review->service_ratings ?? [] as $rating) if ((int) ($rating['staff_profile_id'] ?? 0) === $staff->id) $ratings[] = (float) $rating['rating'];
        });
        $data = ['id' => (string) $staff->id, 'userId' => $staff->user_id ? (string) $staff->user_id : null, 'fullName' => $staff->full_name, 'professionalTitle' => $staff->position, 'bio' => $staff->bio, 'experienceYears' => $staff->experience_years, 'avatarUrl' => $images[0]['url'] ?? null, 'images' => $images, 'specialties' => $staff->offerings()->with('service')->get()->map(fn ($o) => $o->service?->name)->filter()->unique()->values()->all(), 'branch' => ['id' => (string) $staff->branch_id, 'name' => $staff->branch->name, 'business' => ['id' => (string) $staff->business_id, 'name' => $staff->business->name]]];
        $data['ratingCount'] = count($ratings);
        $data['rating'] = $ratings ? round(array_sum($ratings) / count($ratings), 1) : null;
        $position = trim((string) $staff->position);
        $normalized = strtolower(\Illuminate\Support\Str::ascii($position));
        $data['professionalTitle'] = ! $position || preg_match('/\b(branch manager|receptionist|business owner|platform admin|admin|manager|owner|quan ly|le tan|chu doanh nghiep|chu co so|quan tri)\b/i', str_replace(['_', '-'], ' ', $normalized)) ? 'Chuyên viên làm đẹp' : $position;
        if ($detail) $data['staffServices'] = $staff->offerings()->where('is_active', true)->where('bookable', true)->get()->map(fn ($o) => ['service' => $this->service($o)])->all();
        return $data;
    }

    public function review(Review $review, bool $management = false): array
    {
        $data = ['id' => (string) $review->id, 'bookingId' => (string) $review->booking_id, 'overallRating' => $review->overall_rating, 'comment' => $review->comment, 'status' => $review->status, 'isAnonymous' => (bool) $review->is_anonymous, 'createdAt' => $review->created_at?->toIso8601String(), 'customerName' => $review->is_anonymous ? 'Ẩn danh' : ($review->customerProfile?->user?->name ?? 'Ẩn danh'), 'branchName' => $review->booking?->branch?->name, 'businessReply' => $review->business_reply ? ['content' => $review->business_reply, 'updatedAt' => $review->replied_at] : null,
            'serviceRatings' => array_map(fn ($rating) => ['serviceName' => $rating['service_name'] ?? null, 'rating' => $rating['rating'], 'comment' => $rating['comment'] ?? null], $review->service_ratings ?? [])];
        if ($management) {
            $data['customer'] = ['user' => ['fullName' => $data['customerName']]];
            $data['booking'] = ['id' => (string) $review->booking_id, 'bookingCode' => $review->booking->booking_code, 'appointmentDate' => $review->booking->appointment_start_at?->toIso8601String(), 'branch' => ['id' => (string) $review->booking->branch_id, 'name' => $review->booking->branch->name, 'businessId' => (string) $review->booking->business_id]];
            $data['serviceRatings'] = array_map(function ($rating) {
                $staff = isset($rating['staff_profile_id']) ? StaffProfile::withTrashed()->find($rating['staff_profile_id']) : null;
                return ['id' => (string) $rating['booking_service_id'], 'rating' => $rating['rating'], 'comment' => $rating['comment'] ?? null, 'bookingService' => ['id' => (string) $rating['booking_service_id'], 'service' => ['name' => $rating['service_name'] ?? null]], 'staff' => $staff ? ['id' => (string) $staff->id, 'fullName' => $staff->full_name] : null];
            }, $review->service_ratings ?? []);
            $data['reports'] = \Illuminate\Support\Facades\DB::table('review_reports')->where('review_id', $review->id)->orderByDesc('id')->get()->map(fn ($report) => ['id' => (string) $report->id, 'reason' => $report->reason, 'category' => $report->category, 'severity' => $report->severity, 'createdAt' => $report->created_at])->all();
        }
        return $data;
    }

    private function hoursSummary(array $hours): ?string
    {
        $open = array_filter($hours, fn ($h) => ! $h['isClosed']);
        if (! $open) return null;
        $first = array_values($open)[0];
        return substr($first['openTime'], 0, 5).' – '.substr($first['closeTime'], 0, 5);
    }

    private function district(int $branchId): ?array
    {
        $raw = \Illuminate\Support\Facades\DB::table('beautybook_branch_settings')->where('branch_id', $branchId)->value('metadata');
        $metadata = $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        return collect(app(SourceDistrictCatalog::class)->all())->firstWhere('id', $metadata['districtId'] ?? null);
    }
}
