<?php

namespace App\Services\Beautybook;

use App\Models\{Business, Branch, BusinessService, BranchServiceOffering, StaffProfile, MediaFile, Combo};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManagementPresenter
{
    public function camel(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $name = is_string($key) ? Str::camel($key) : $key;
            if (is_array($value)) $value = $this->camel($value);
            if (is_string($key) && str_ends_with($key, '_at') && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value)) $value = \Carbon\CarbonImmutable::parse($value, config('app.timezone'))->utc()->toISOString();
            if (($name === 'id' || str_ends_with((string) $name, 'Id')) && $value !== null && is_numeric($value)) $value = (string) $value;
            $result[$name] = $value;
        }
        return $result;
    }

    public function media(MediaFile $media): array
    {
        return $this->camel($media->toArray()) + ['url' => $media->entity_type==='USER_AVATAR' ? '/api/v1/users/me/avatar' : ($media->visibility === 'PUBLIC' ? route('media.show', $media, false) : null)];
    }

    public function business(Business $business): array
    {
        $settings = DB::table('beautybook_business_settings')->where('business_id', $business->id)->first();
        $metadata = json_decode($settings?->metadata ?? '{}', true);
        $business->load(['ownerProfile.user','reviewEvents','branches']);
        $owner = $this->camel($business->ownerProfile?->toArray() ?? []);
        if ($user = $business->ownerProfile?->user) {
            $owner['user'] = ['id'=>(string)$user->id,'fullName'=>$user->name,'email'=>$user->email,'phone'=>DB::table('beautybook_user_profiles')->where('user_id',$user->id)->value('phone'),'isActive'=>(bool)$user->is_active,'createdAt'=>$user->created_at?->toIso8601String()];
        }
        $catalog = $business->services()->with(['offerings','canonicalService'])->latest()->get();
        $branchIds = $business->branches->modelKeys();
        $reviews = \App\Models\Review::where('status','APPROVED')->whereHas('booking',fn ($q)=>$q->whereIn('branch_id',$branchIds));
        return array_merge($this->camel($business->load(['ownerProfile.user','reviewEvents'])->toArray()), $metadata, [
            'owner' => $owner,
            'summary' => ['branchCount'=>count($branchIds),'activeBranchCount'=>$business->branches->filter(fn ($branch)=>$branch->status->value==='ACTIVE')->count(),'serviceCount'=>$catalog->count(),'bookingCount'=>\App\Models\Booking::whereIn('branch_id',$branchIds)->count(),'averageRating'=>($average=(clone $reviews)->avg('overall_rating'))===null ? null : round((float)$average,1),'reviewCount'=>(clone $reviews)->count()],
            'members' => \App\Models\RoleAssignment::with('user','branch')->where('business_id',$business->id)->where(fn ($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()->utc()))->get()->unique('user_id')->map(fn ($assignment)=>['id'=>(string)$assignment->id,'roleCode'=>$assignment->role_name,'user'=>['id'=>(string)$assignment->user_id,'fullName'=>$assignment->user->name,'email'=>$assignment->user->email,'isActive'=>(bool)$assignment->user->is_active],'branch'=>$assignment->branch ? ['id'=>(string)$assignment->branch_id,'name'=>$assignment->branch->name] : null])->values()->all(),
            'serviceCatalog' => $catalog->map(fn ($service)=>$this->catalog($service)+['_count'=>['branchServices'=>$service->offerings->count()]])->all(),
            'auditTrail' => DB::table('audit_logs')->where('business_id',$business->id)->latest()->limit(100)->get()->map(fn ($row)=>['id'=>(string)$row->id,'action'=>$row->action,'createdAt'=>$row->created_at])->all(),
            'images' => $this->images('business_images','business_id',$business->id),
            'generatedMedia' => app(SourceReferenceMedia::class)->for('BUSINESS',$business->id),
            'companyName' => $business->ownerProfile?->company_name, 'taxCode' => $business->ownerProfile?->tax_code,
            'documents' => $this->documents($business->id), 'legalDocuments' => array_map(fn ($doc) => $doc + ['mediaId' => $doc['versions'][0]['media']['id'] ?? null, 'media' => $doc['versions'][0]['media'] ?? null, 'documentUrl' => '/api/v1/media/'.($doc['versions'][0]['media']['id'] ?? '').'/content'], $this->documents($business->id)),
            'branches' => $business->branches->map(fn ($branch) => $this->branch($branch))->all(),
            'onboardingStep' => $metadata['onboardingStep'] ?? 1, 'onboardingData' => $metadata['onboardingData'] ?? [],
        ]);
    }

    public function branch(Branch $branch): array
    {
        $settings = DB::table('beautybook_branch_settings')->where('branch_id', $branch->id)->first();
        $metadata = json_decode($settings?->metadata ?? '{}', true);
        $onboarding = json_decode($settings?->onboarding ?? '{}', true);
        $policy = json_decode($settings?->booking_policy ?? '{}',true);
        $missing = [];
        if (!in_array($branch->business->status->value,['APPROVED','ACTIVE'])) $missing[] = 'Doanh nghiệp chưa được duyệt';
        if ($branch->review_status->value!=='APPROVED') $missing[] = 'Chi nhánh chưa được duyệt';
        if (($metadata['serviceMode'] ?? 'AT_LOCATION')!=='MOBILE' && (!$branch->address_line || empty($metadata['districtId']))) $missing[] = 'Chưa hoàn tất địa chỉ chi nhánh';
        if (($metadata['serviceMode'] ?? 'AT_LOCATION')==='MOBILE' && empty($metadata['serviceAreas'])) $missing[] = 'Chưa cấu hình khu vực phục vụ tại nhà';
        if (!$branch->phone && !$branch->email) $missing[] = 'Chưa có thông tin liên hệ';
        if (!$branch->workingHours()->where('is_closed',false)->exists()) $missing[] = 'Chưa cấu hình giờ mở cửa';
        if (!$branch->offerings()->where('bookable', true)->where('is_active',true)->exists()) $missing[] = 'Chưa có dịch vụ đang hoạt động';
        if (!$branch->staffProfiles()->where('status','ACTIVE')->where('is_bookable',true)->exists()) $missing[] = 'Chưa có chuyên viên được bật nhận lịch';
        if (empty($policy['confirmedAt'])) $missing[] = 'Chưa xác nhận chính sách đặt lịch';
        if ($branch->business->booking_restricted_at) $missing[] = $branch->business->booking_restriction_reason ?: 'Đang bị hạn chế nhận booking';
        return array_merge($this->camel($branch->load('workingHours')->toArray()), $metadata, [
            'address' => $branch->address_line, 'openingHours' => $branch->workingHours->map(fn ($hour) => ['dayOfWeek' => $hour->day_of_week, 'isClosed' => $hour->is_closed, 'openTime' => substr($hour->open_time ?? '',0,5), 'closeTime' => substr($hour->close_time ?? '',0,5)])->all(),
            'workingHours' => $branch->workingHours->map(fn ($hour) => ['dayOfWeek' => $hour->day_of_week, 'isClosed' => (bool)$hour->is_closed, 'openTime' => '1970-01-01T'.($hour->open_time ?? '00:00:00').'.000Z', 'closeTime' => '1970-01-01T'.($hour->close_time ?? '00:00:00').'.000Z'])->all(),
            'onboarding' => $onboarding, 'onboardingProgress' => $onboarding, 'onboardingState' => $onboarding, 'bookingPolicy' => json_decode($settings?->booking_policy ?? '{}',true),
            'documents' => $this->documents($branch->business_id,$branch->id), 'readiness' => ['ready' => !$missing, 'canSubmit' => !$missing, 'missing' => $missing, 'reasons' => $missing, 'issues' => $missing,'completed'=>max(0,8-count($missing)),'total'=>8,'progress'=>(int)round(max(0,8-count($missing))/8*100),'reviewStatus'=>$branch->review_status->value,'operationalStatus'=>$branch->operational_status->value],
            'images' => $this->images('branch_images','branch_id',$branch->id),
            'generatedMedia' => app(SourceReferenceMedia::class)->for('BRANCH',$branch->id),
            'staffAssignments' => \App\Models\StaffBranchAssignment::where('branch_id',$branch->id)->where('status','ACTIVE')->get()->map(fn($assignment)=>$this->camel($assignment->toArray()) + ['staffId'=>(string)$assignment->staff_profile_id])->all(),
            'services' => $branch->offerings()->with('service')->get()->map(fn ($row) => $this->offering($row))->all(),
            'staff' => $branch->staffProfiles()->get()->map(fn ($row) => $this->staff($row))->all(),
            '_count' => ['services'=>$branch->offerings()->count(),'staff'=>$branch->staffProfiles()->count(),'bookings'=>\App\Models\Booking::where('branch_id',$branch->id)->count()],
        ]);
    }

    public function documents(int $businessId, ?int $branchId = null): array
    {
        return DB::table('beautybook_documents')->where('business_id',$businessId)->where('branch_id',$branchId)->where('status','ACTIVE')->get()->map(function ($doc) {
            $row = $this->camel((array)$doc);
            $row['versions'] = DB::table('beautybook_document_versions')->where('document_id',$doc->id)->orderByDesc('version')->get()->map(fn ($v) => $this->camel((array)$v) + ['media' => $this->media(MediaFile::findOrFail($v->media_id))])->all();
            return $row;
        })->all();
    }

    public function offering(BranchServiceOffering $offering): array
    {
        return array_merge($this->camel($offering->toArray()), [
            'id' => (string)$offering->id, 'name' => $offering->service->name, 'description' => $offering->service->description,
            'price' => (float)$offering->price, 'durationMinutes' => $offering->duration_minutes, 'serviceId' => (string)$offering->id,
            'catalogServiceId' => (string)$offering->business_service_id, 'branchId' => (string)$offering->branch_id,
            'canonicalService' => $this->camel($offering->service->canonicalService?->toArray() ?? []),
            'images' => $this->images('service_images','branch_service_offering_id',$offering->id),
            'generatedMedia' => app(SourceReferenceMedia::class)->for('SERVICE',$offering->id),
        ]);
    }

    public function catalog(BusinessService $service, ?int $branchId = null): array
    {
        $branches = Branch::where('business_id',$service->business_id)->when($branchId,fn ($q) => $q->whereKey($branchId))->get();
        $offeringIds = $service->offerings->when($branchId, fn ($rows) => $rows->where('branch_id', $branchId))->pluck('id');
        $staffCounts = $offeringIds->isEmpty() ? collect() : DB::table('staff_services')
            ->join('staff_profiles', 'staff_profiles.id', '=', 'staff_services.staff_profile_id')
            ->leftJoin('users', 'users.id', '=', 'staff_profiles.user_id')
            ->whereIn('staff_services.branch_service_offering_id', $offeringIds)
            ->where('staff_services.business_id', $service->business_id)
            ->whereColumn('staff_services.business_id', 'staff_profiles.business_id')
            ->whereColumn('staff_services.branch_id', 'staff_profiles.branch_id')
            ->where('staff_profiles.status', 'ACTIVE')->where('staff_profiles.is_bookable', true)
            ->whereNull('staff_profiles.deleted_at')
            ->where(fn ($query) => $query->whereNull('staff_profiles.user_id')->orWhere('users.is_active', true))
            ->selectRaw('staff_services.branch_service_offering_id, COUNT(DISTINCT staff_services.staff_profile_id) AS total')
            ->groupBy('staff_services.branch_service_offering_id')->pluck('total', 'branch_service_offering_id');
        $futureBookingCounts = $offeringIds->isEmpty() ? collect() : DB::table('booking_services')
            ->join('bookings', 'bookings.id', '=', 'booking_services.booking_id')
            ->whereIn('booking_services.branch_service_offering_id', $offeringIds)
            ->where('booking_services.business_id', $service->business_id)
            ->whereColumn('booking_services.business_id', 'bookings.business_id')
            ->whereColumn('booking_services.branch_id', 'bookings.branch_id')
            ->whereIn('bookings.status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'])
            ->where('bookings.appointment_start_at', '>', now('UTC'))
            ->where('booking_services.status', 'SCHEDULED')->whereNull('bookings.deleted_at')
            ->selectRaw('booking_services.branch_service_offering_id, COUNT(DISTINCT bookings.id) AS total')
            ->groupBy('booking_services.branch_service_offering_id')->pluck('total', 'branch_service_offering_id');
        $availability = $branches->map(function ($branch) use ($service, $staffCounts, $futureBookingCounts) {
            $offering = $service->offerings->firstWhere('branch_id',$branch->id);
            return ['branchId' => (string)$branch->id, 'branchName' => $branch->name, 'branchServiceId' => $offering ? (string)$offering->id : null,
                'status' => !$offering ? 'NOT_APPLIED' : ($offering->bookable && $offering->is_active && $service->status === 'ACTIVE' ? 'ACTIVE' : 'PAUSED'),
                'price' => $offering ? (float)$offering->price : (float)$service->base_price, 'durationMinutes' => $offering?->duration_minutes ?? $service->base_duration_minutes,
                'staffCount' => $offering ? (int)$staffCounts->get($offering->id, 0) : 0,
                'futureBookingCount' => $offering ? (int)$futureBookingCounts->get($offering->id, 0) : 0];
        });
        $primaryMedia = $offeringIds->isEmpty() ? null : MediaFile::query()
            ->join('service_images', 'service_images.media_file_id', '=', 'media_files.id')
            ->whereIn('service_images.branch_service_offering_id', $offeringIds)
            ->where('media_files.visibility', 'PUBLIC')
            ->orderBy('service_images.sort_order')->orderBy('service_images.id')
            ->select('media_files.*')->first();
        return array_merge($this->camel($service->toArray()), ['price' => (float)$service->base_price,'durationMinutes' => $service->base_duration_minutes,
            'primaryImageUrl' => $primaryMedia ? route('media.show', $primaryMedia, false) : null,
            'canonicalServiceId' => $service->canonical_service_id ? (string)$service->canonical_service_id : null,
            'canonicalService' => $this->camel($service->canonicalService?->toArray() ?? []), 'branchAvailability' => $availability->all(),
            'branchCoverage' => ['total' => $availability->count(), 'active' => $availability->where('status','ACTIVE')->count(), 'paused' => $availability->where('status','PAUSED')->count(), 'notApplied' => $availability->where('status','NOT_APPLIED')->count()]]);
    }

    public function staff(StaffProfile $staff): array
    {
        $staff->load(['branch','user','assignments','offerings.service']);
        $data = $this->camel($staff->toArray());
        $data['user'] = $staff->user ? ['id'=>(string)$staff->user->id,'fullName'=>$staff->user->name,'email'=>$staff->user->email,'isActive'=>(bool)$staff->user->is_active] : null;
        $data['services'] = $staff->offerings->map(fn ($offering) => $this->offering($offering))->all();
        $data['branchAssignments'] = $this->camel($staff->assignments->toArray());
        $data['branchAssignments'] = $staff->assignments->map(fn($assignment) => $this->camel($assignment->toArray()) + ['branch' => ['id'=>(string)$assignment->branch_id,'name'=>Branch::find($assignment->branch_id)?->name]])->all();
        $data['staffServices'] = $staff->offerings->map(fn($offering) => ['id'=>(string)$offering->pivot->branch_service_offering_id,'service'=>$this->offering($offering)])->all();
        $data['invitations'] = $this->camel(\App\Models\StaffInvitation::where('staff_profile_id',$staff->id)->latest()->get()->map(fn($invite)=>collect($invite->toArray())->except('token_hash')->all())->all());
        $data['auditTrail'] = DB::table('audit_logs')->where('entity_type','StaffProfile')->where('entity_id',(string)$staff->id)->orderByDesc('id')->limit(100)->get()->map(fn($row)=>['id'=>(string)$row->id,'action'=>$row->action,'createdAt'=>$row->created_at])->all();
        $data['images'] = $this->images('staff_images','staff_profile_id',$staff->id);
        return $data;
    }

    public function combo(Combo $combo): array
    {
        $data = $this->camel($combo->load('items.offering.service')->toArray());
        $data['comboPrice'] = (float)$combo->combo_price;
        $data['items'] = $combo->items->map(fn ($item) => $this->camel($item->toArray()) + ['serviceId'=>(string)$item->branch_service_offering_id, 'service'=>$this->offering($item->offering)])->all();
        $data['services'] = $data['items'];
        $data['images'] = MediaFile::where('entity_type','COMBO_IMAGE')->where('entity_id',$combo->id)->where('visibility','PUBLIC')->get()->map(fn($media)=>['id'=>(string)$media->id,'media'=>$this->media($media)])->all();
        return $data;
    }

    private function images(string $table,string $key,int $id): array
    {
        return DB::table($table)->where($key,$id)->orderBy('sort_order')->get()->map(fn($image)=>['id'=>(string)$image->id,'media'=>$this->media(MediaFile::findOrFail($image->media_file_id))])->all();
    }
}
