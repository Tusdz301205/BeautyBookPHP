<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\{Booking,Branch,Business,OperationalImpactCase,OperationalImpactItem};
use App\Services\Beautybook\{ApiScope,AuditLogDisclosure,GovernancePresenter,ManagementPresenter};
use App\Services\Operations\ImpactService;
use App\Services\Ownership\OwnershipService;
use App\Services\Platform\{GovernanceService,PlatformSettingsService};
use App\Services\Privacy\PrivacyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GovernanceController extends Controller
{
    public function __construct(private ApiScope $scope, private GovernancePresenter $dto) {}

    public function publicSettings(PlatformSettingsService $settings): array
    {
        return array_intersect_key($settings->get(), array_flip(['maxAdvanceBookingDays','minBookingLeadTimeHours','freeCancellationHours','allowRescheduleRequests','maxRescheduleCountPerBooking','reviewMinLength','allowAnonymousReview']));
    }

    public function settings(Request $r, PlatformSettingsService $settings): array
    {
        $this->scope->platform($r);
        $raw = DB::table('platform_settings')->where('key','platform')->value('value');
        return ['configured' => (object) ($raw ? json_decode($raw,true) : []), 'defaults' => PlatformSettingsService::DEFAULTS, 'effective' => $settings->get()];
    }

    public function updateSettings(Request $r, PlatformSettingsService $settings): array
    {
        $this->scope->platform($r);
        $data = $r->validate(['settings' => 'present|array']);
        // Replace the override set, so removing a setting restores its default.
        DB::transaction(function () use ($r,$settings,$data) {
            $settings->update($data['settings'] ?: PlatformSettingsService::DEFAULTS,$r->user()->id);
            DB::table('platform_settings')->where('key','platform')->update(['value' => json_encode($data['settings'],JSON_THROW_ON_ERROR)]);
        });
        return $this->settings($r,$settings);
    }

    public function resetSettings(Request $r, PlatformSettingsService $settings): array
    {
        $this->scope->platform($r);
        DB::transaction(function () use ($r,$settings) {
            $settings->update(PlatformSettingsService::DEFAULTS, $r->user()->id);
            DB::table('platform_settings')->where('key','platform')->update(['value' => '{}']);
        });
        return $this->settings($r,$settings);
    }

    public function audit(Request $r)
    {
        $a = $this->scope->forRoles($r,['PLATFORM_ADMIN','BUSINESS_OWNER']);
        return DB::table('audit_logs')->when($a->role_name !== 'PLATFORM_ADMIN',fn($q) => $q->where('user_id',$r->user()->id))->orderByDesc('id')->limit(100)->get()->map(function($row) {
            // The presenter decodes arbitrary metadata; never send that raw payload to the browser.
            $result = $this->dto->row($row);
            $actor = DB::table('users')->find($row->user_id);
            $result['user'] = $actor ? ['id'=>(string)$actor->id,'fullName'=>$actor->name,'email'=>$actor->email] : null;
            $meta = $result['metadata'] ?? null;
            unset($result['metadata']);
            return $result + AuditLogDisclosure::changes($meta);
        });
    }

    public function trustSnapshots(Request $r)
    {
        $this->scope->platform($r);
        $rows=DB::table('trust_snapshots')->orderBy('trust_score')->get();
        return ['periodDays'=>90,'dangerCount'=>$rows->where('alert_level','DANGER')->count(),'warningCount'=>$rows->where('alert_level','WARN')->count(),'monitoredCount'=>$rows->where('alert_level','!=','OK')->count(),'totalBusinesses'=>$rows->count(),'lastComputedAt'=>$rows->max('computed_at'),'data'=>$rows->map(fn($row) => $this->dto->row($row) + ['business'=>['id'=>(string)$row->business_id,'name'=>Business::find($row->business_id)?->name,'branches'=>Branch::where('business_id',$row->business_id)->get()->map(fn($b)=>['id'=>(string)$b->id,'name'=>$b->name,'status'=>$b->status->value])]])];
    }

    public function rebuildTrust(Request $r, GovernanceService $service): array
    {
        $this->scope->platform($r);
        $service->rebuildTrust($r->user()->id);
        return $this->trustSnapshots($r);
    }

    public function trustActions(Request $r)
    {
        $this->scope->platform($r);
        return DB::table('trust_actions')->latest()->get()->map(function($row) { $actor=DB::table('users')->find($row->actor_user_id); return $this->dto->row($row)+['business'=>['id'=>(string)$row->business_id,'name'=>Business::find($row->business_id)?->name],'branch'=>$row->branch_id ? ['id'=>(string)$row->branch_id,'name'=>Branch::find($row->branch_id)?->name] : null,'actor'=>$actor ? ['id'=>(string)$actor->id,'fullName'=>$actor->name,'email'=>$actor->email] : null]; });
    }

    public function createTrustAction(Request $r, GovernanceService $service)
    {
        $this->scope->platform($r);
        $data=$r->validate(['businessId'=>'required|integer','branchId'=>'nullable|integer','action'=>'required|in:WARNING_SENT,EXPLANATION_REQUESTED,MONITORING_STARTED,BOOKING_RESTRICTED,SUSPENDED,RESTORED,NOTE_ADDED','reason'=>'required|string|max:2000','internalNote'=>'nullable|string|max:4000']);
        $row=$service->trustAction($r,Business::findOrFail($data['businessId']),['branch_id'=>$data['branchId'] ?? null,'action'=>$data['action'],'reason'=>$data['reason'],'internal_note'=>$data['internalNote'] ?? null]);
        return response()->json($this->dto->row($row),$row->outcome === 'APPLIED' ? 201 : 409);
    }

    public function businessDirectory(Request $r, ManagementPresenter $presenter): array
    {
        $this->scope->platform($r);
        $query=Business::query()->with('branches');
        if($r->search || $r->q) { $term='%'.($r->search ?: $r->q).'%'; $query->where(fn($q)=>$q->where('name','like',$term)->orWhereHas('ownerProfile.user',fn($u)=>$u->where('name','like',$term)->orWhere('email','like',$term))); }
        if($r->status && $r->status !== 'ALL') $query->where('status',$r->status);
        $result=$this->scope->paginate($query->orderBy('name'),$r,function($row)use($presenter) { $data=$presenter->business($row); $user=$row->ownerProfile?->user; return $data+['owner'=>$user ? ['id'=>(string)$user->id,'fullName'=>$user->name,'email'=>$user->email] : null,'branchCount'=>$row->branches->count(),'activeBranchCount'=>$row->branches->filter(fn($branch)=>$branch->status->value==='ACTIVE')->count(),'serviceCount'=>DB::table('business_services')->where('business_id',$row->id)->whereNull('deleted_at')->count(),'bookingCount'=>Booking::where('business_id',$row->id)->count()]; });
        return ['data'=>$result['data'],'pagination'=>array_diff_key($result,['data'=>true])];
    }

    public function branchDirectory(Request $r, ManagementPresenter $presenter): array
    {
        $this->scope->platform($r);
        $query=Branch::query();
        if($r->businessId) $query->where('business_id',$r->businessId);
        if($r->search || $r->q) { $term='%'.($r->search ?: $r->q).'%'; $query->where(fn($q)=>$q->where('name','like',$term)->orWhere('public_name','like',$term)->orWhere('address_line','like',$term)->orWhereHas('business',fn($b)=>$b->where('name','like',$term))); }
        if($r->status && $r->status !== 'ALL') $query->where(in_array($r->status,['DRAFT','SUBMITTED','PENDING_REVIEW','NEED_MORE_INFO','APPROVED','REJECTED'],true) ? 'review_status' : 'status',$r->status);
        $result=$this->scope->paginate($query->orderBy('name'),$r,function($row)use($presenter) { $data=$presenter->branch($row); $rating=DB::table('reviews')->join('bookings','bookings.id','=','reviews.booking_id')->where('bookings.branch_id',$row->id)->where('reviews.status','APPROVED')->avg('overall_rating'); return array_replace($data,['business'=>['id'=>(string)$row->business_id,'name'=>$row->business?->name,'status'=>$row->business?->status->value],'services'=>$row->offerings()->count(),'bookings'=>Booking::where('branch_id',$row->id)->count(),'rating'=>$rating ? round((float)$rating,1) : 0,'location'=>$row->address_line]); });
        return ['data'=>$result['data'],'pagination'=>array_diff_key($result,['data'=>true])];
    }

    public function compliance(Request $r, ManagementPresenter $presenter): array
    {
        $this->scope->platform($r);
        return ['pendingBusinesses'=>Business::whereIn('status',['PENDING','PENDING_REVIEW','NEED_MORE_INFO','REJECTED','APPROVED','ACTIVE'])->get()->map(function($b)use($presenter) { $data=$presenter->business($b); $user=$b->ownerProfile?->user; return array_replace($data,['owner'=>['user'=>$user ? ['id'=>(string)$user->id,'fullName'=>$user->name,'email'=>$user->email] : null]]); }), 'pendingBranches'=>Branch::whereIn('review_status',['SUBMITTED','PENDING_REVIEW','NEED_MORE_INFO','REJECTED','APPROVED'])->get()->map(fn($b)=>array_replace($presenter->branch($b),['business'=>['id'=>(string)$b->business_id,'name'=>$b->business?->name,'status'=>$b->business?->status->value]]))];
    }

    public function marketing(Request $r): array
    {
        $this->scope->platform($r);
        return ['promotions'=>DB::table('promotions')->where('status','ACTIVE')->latest()->limit(50)->get()->map(fn($row)=>$this->dto->row($row)), 'vouchers'=>DB::table('vouchers')->where('status','ACTIVE')->latest()->limit(50)->get()->map(fn($row)=>$this->dto->row($row))];
    }

    public function privacyRequests(Request $r)
    {
        $customer=$this->scope->customer($r);
        return DB::table('data_subject_requests')->where('customer_profile_id',$customer->id)->latest()->get()->map(fn($row)=>$this->dto->row($row));
    }

    public function privacyPreferences(Request $r): array
    {
        $customer=$this->scope->customer($r);
        $row=DB::table('marketing_preferences')->where('customer_profile_id',$customer->id)->first();
        return ['emailMarketing'=>(bool)($row?->email_marketing ?? false),'smsMarketing'=>(bool)($row?->sms_marketing ?? false),'pushMarketing'=>(bool)($row?->push_marketing ?? false),'personalizedPromotions'=>(bool)($row?->personalized_promotions ?? false),'updatedAt'=>$row?->updated_at];
    }

    public function privacyCenter(Request $r): array
    {
        $customer=$this->scope->customer($r);
        return ['account'=>['email'=>$r->user()->email,'fullName'=>$r->user()->name,'createdAt'=>$r->user()->created_at], 'summary'=>['bookingCount'=>Booking::where('customer_profile_id',$customer->id)->count(),'submissionCount'=>0,'activeShareCount'=>0,'accessEventCount'=>0], 'sections'=>['shares'=>[],'accessHistory'=>[],'retention'=>[],'dataRequests'=>$this->privacyRequests($r),'marketing'=>$this->privacyPreferences($r)]];
    }

    public function requestData(Request $r, PrivacyService $service)
    {
        $this->scope->customer($r);
        $data=$r->validate(['type'=>'required|in:EXPORT,RECTIFICATION,ERASURE,RESTRICT_PROCESSING,OBJECT_PROCESSING,DELETE_ACCOUNT','reason'=>'nullable|string|max:2000']);
        return response()->json($this->dto->row($service->requestData($r,$data)),201);
    }

    public function updatePreferences(Request $r): array
    {
        $customer=$this->scope->customer($r);
        $rules=array_fill_keys(['emailMarketing','smsMarketing','pushMarketing','personalizedPromotions'],'required|boolean');
        $data=$r->validate($rules);
        DB::transaction(function() use($r,$customer,$data) {
            DB::table('customer_profiles')->where('id',$customer->id)->lockForUpdate()->first();
            $values=[]; foreach($data as$key=>$value) $values[\Illuminate\Support\Str::snake($key)]=(bool)$value;
            DB::table('marketing_preferences')->updateOrInsert(['customer_profile_id'=>$customer->id],$values+['updated_at'=>now()]);
            DB::table('audit_logs')->insert(['user_id'=>$r->user()->id,'action'=>'UPDATE','entity_type'=>'MarketingPreference','entity_id'=>(string)$customer->id,'created_at'=>now()]);
        });
        return $this->privacyPreferences($r);
    }

    public function exportData(Request $r, PrivacyService $service)
    {
        $this->scope->customer($r);
        $r->validate(['currentPassword'=>'required|string|max:200']);
        $result=$service->export($r,$r->currentPassword);
        return response()->json(['id'=>(string)$result['id'],'downloadToken'=>$result['download_token'],'expiresAt'=>$result['expires_at']],201)->header('Cache-Control','no-store');
    }

    public function downloadData(Request $r, int $id, PrivacyService $service)
    {
        $this->scope->customer($r);
        $token=$r->header('X-Privacy-Download-Token');
        abort_unless(is_string($token)&&strlen($token)===64,422);
        return response($service->download($r,$id,$token))->header('Content-Type','application/json')->header('Cache-Control','no-store');
    }

    public function impacts(Request $r, ImpactService $service)
    {
        $r=$this->scope->requestForRoles($r,['PLATFORM_ADMIN','BUSINESS_OWNER']);
        $a=$this->scope->current($r); $service->access($r);
        return OperationalImpactCase::when($a->role_name !== 'PLATFORM_ADMIN',fn($q)=>$q->where('business_id',$a->business_id))->latest()->get()->map(fn($row)=>$this->impactDto($row));
    }

    private function impactDto(OperationalImpactCase $case): array
    {
        $case->load('items.booking');
        $result=$this->dto->row($case->getAttributes());
        $result['subjectType']=$case->subject_staff_profile_id ? 'STAFF' : 'BRANCH';
        $result['subjectId']=(string) ($case->subject_staff_profile_id ?: $case->branch_id);
        $result['action']=$case->subject_staff_profile_id ? 'OFFBOARD' : $case->trigger_type;
        $result['items']=$case->items->map(fn($item)=>$this->dto->row($item->getAttributes())+['booking'=>$item->booking ? ['id'=>(string)$item->booking->id,'bookingCode'=>$item->booking->booking_code] : null])->all();
        return $result;
    }

    public function impactDetail(Request $r,int $id,ImpactService $service): array
    {
        $case=OperationalImpactCase::findOrFail($id); $r=$this->scope->requestForRoles($r,['PLATFORM_ADMIN','BUSINESS_OWNER'],$case->business_id); $service->access($r,$case); return $this->impactDto($case);
    }

    private function impactData(Request $r,OperationalImpactCase $case): array
    {
        $data=$r->validate(['resolution'=>'required|in:REASSIGN,RESCHEDULE,TRANSFER_BRANCH,CANCEL_REFUND,APPROVED_EXCEPTION','reason'=>'required|string|max:2000','replacementStaffId'=>'nullable|required_if:resolution,REASSIGN,TRANSFER_BRANCH|integer','replacementBranchId'=>'nullable|required_if:resolution,TRANSFER_BRANCH|integer','proposedStartAt'=>'nullable|required_if:resolution,RESCHEDULE|date']);
        return ['resolution'=>$data['resolution'],'reason'=>$data['reason'],'replacement_staff_id'=>$data['replacementStaffId'] ?? null,'replacement_branch_id'=>$data['replacementBranchId'] ?? null,'proposed_start_at'=>isset($data['proposedStartAt']) ? CarbonImmutable::parse($data['proposedStartAt'])->setTimezone($case->branch->timezone)->format('Y-m-d\TH:i') : null];
    }

    public function resolveImpact(Request $r,int $id,int $itemId,ImpactService $service): array
    {
        $case=OperationalImpactCase::findOrFail($id); $r=$this->scope->requestForRoles($r,['PLATFORM_ADMIN','BUSINESS_OWNER'],$case->business_id); $service->access($r,$case); $item=$case->items()->findOrFail($itemId);
        return $this->impactDto($service->resolve($r,$case,$item,$this->impactData($r,$case)));
    }

    public function batchImpact(Request $r,int $id,ImpactService $service): array
    {
        $case=OperationalImpactCase::findOrFail($id); $r=$this->scope->requestForRoles($r,['PLATFORM_ADMIN','BUSINESS_OWNER'],$case->business_id); $service->access($r,$case);
        $r->validate(['itemIds'=>'required|array|min:1|max:100','itemIds.*'=>'required|integer|distinct']);
        $result=$service->batch($r,$case,$this->impactData($r,$case)+['item_ids'=>$r->itemIds]);
        return ['impact'=>$this->impactDto($case->fresh()),'results'=>array_map(fn($row)=>['itemId'=>(string)$row['item_id'],'ok'=>$row['ok'],'error'=>$row['error'] ?? null],$result['results'])];
    }

    public function completeImpact(Request $r,int $id,ImpactService $service): array
    {
        $case=OperationalImpactCase::findOrFail($id); $r=$this->scope->requestForRoles($r,['PLATFORM_ADMIN','BUSINESS_OWNER'],$case->business_id); $service->access($r,$case); return $this->impactDto($service->complete($r,$case));
    }

    public function transfers(Request $r)
    {
        $business=$this->scope->owner($r,$r->businessId);
        return DB::table('ownership_transfers')->where('business_id',$business->id)->latest()->get()->map(fn($row)=>$this->dto->transfer($row));
    }

    public function pendingTransfers(Request $r)
    {
        $this->scope->platform($r);
        return DB::table('ownership_transfers')->whereIn('status',['UNDER_REVIEW','NEED_MORE_INFO','APPROVED','SCHEDULED','EXECUTION_FAILED'])->latest()->get()->map(fn($row)=>$this->dto->transfer($row));
    }

    public function incomingTransfers(Request $r)
    {
        $a=$this->scope->current($r); abort_if($a->role_name==='CUSTOMER',403);
        return DB::table('ownership_transfers')->where('new_owner_user_id',$r->user()->id)->where('status','PENDING_NEW_OWNER_ACCEPTANCE')->latest()->get()->map(fn($row)=>$this->dto->transfer($row));
    }

    public function createTransfer(Request $r,OwnershipService $service)
    {
        $data=$r->validate(['businessId'=>'required|integer','newOwnerEmail'=>'required|email','effectiveAt'=>'required|date','reason'=>'required|string|max:2000','settlementAgreement'=>'nullable|array','settlementAgreement.note'=>'nullable|string|max:4000']);
        $business=$this->scope->owner($r,$data['businessId']);
        $r=$this->scope->requestForRoles($r,['BUSINESS_OWNER'],$business->id);
        $id=$service->create($r,$business,['new_owner_email'=>$data['newOwnerEmail'],'effective_at'=>CarbonImmutable::parse($data['effectiveAt'])->utc(),'reason'=>$data['reason'],'settlement_agreement'=>$data['settlementAgreement'] ?? null]);
        return response()->json($this->dto->transfer(DB::table('ownership_transfers')->find($id)),201);
    }

    public function transferAction(Request $r,int $id,string $action,OwnershipService $service): array
    {
        $this->scope->current($r);
        $action=$action==='submit-more-info' ? 'resubmit' : $action;
        if (in_array($action,['cancel','resubmit'],true)) {
            $transfer=DB::table('ownership_transfers')->find($id); abort_unless($transfer,404);
            $r=$this->scope->requestForRoles($r,['BUSINESS_OWNER'],$transfer->business_id);
        }
        $r->validate(['reason'=>in_array($action,['review','cancel']) ? 'required|string|max:2000' : 'nullable|string|max:2000','note'=> $action==='resubmit' ? 'required|string|max:2000' : 'nullable|string|max:2000','approve'=>'sometimes|boolean','needMoreInfo'=>'sometimes|boolean']);
        $service->action($r,$id,$action,['reason'=>$r->reason ?? $r->note,'decision'=>$r->boolean('needMoreInfo') ? 'NEED_MORE_INFO' : ($r->boolean('approve') ? 'APPROVE' : 'REJECT'),'settlement_agreement'=>$r->settlementAgreement]);
        return $this->dto->transfer(DB::table('ownership_transfers')->find($id));
    }

    public function versions(Request $r,int $businessId): array
    {
        $a=$this->scope->current($r); if($a->role_name !== 'PLATFORM_ADMIN') $this->scope->owner($r,$businessId);
        $rows=DB::table('business_identity_versions')->where('business_id',$businessId)->latest('version')->get();
        return ['legalEntities'=>$rows->where('type','LEGAL_ENTITY')->map(fn($row)=>$this->dto->version($row))->values(),'payoutAccounts'=>$rows->where('type','PAYOUT_ACCOUNT')->map(fn($row)=>$this->dto->version($row))->values()];
    }

    public function createVersion(Request $r,int $businessId,string $type,OwnershipService $service)
    {
        $business=$this->scope->owner($r,$businessId);
        $r=$this->scope->requestForRoles($r,['BUSINESS_OWNER'],$businessId);
        $r->validate(['legalName'=>'required_if:type,legal-entity|nullable|string|max:255','taxCode'=>'nullable|string|max:80','registrationNumber'=>'nullable|string|max:100','representativeName'=>'nullable|string|max:255','bankName'=>'nullable|string|max:120','accountHolder'=>'nullable|string|max:255','accountNumber'=>'nullable|string|max:100']);
        if($type==='legal-entity') $r->validate(['legalName'=>'required|string|max:255']); else $r->validate(['bankName'=>'required|string|max:120','accountHolder'=>'required|string|max:255','accountNumber'=>'required|string|max:100']);
        $id=$service->identity($r,$business,['type'=>$type==='legal-entity'?'LEGAL_ENTITY':'PAYOUT_ACCOUNT','legal_name'=>$r->legalName,'tax_code'=>$r->taxCode,'registration_number'=>$r->registrationNumber,'representative_name'=>$r->representativeName,'bank_name'=>$r->bankName,'account_holder'=>$r->accountHolder,'account_number'=>$r->accountNumber]);
        return response()->json($this->dto->version(DB::table('business_identity_versions')->find($id)),201);
    }

    public function verifyVersion(Request $r,int $id,string $type,OwnershipService $service): array
    {
        $this->scope->platform($r);
        abort_unless(DB::table('business_identity_versions')->where('id',$id)->where('type',$type==='legal-entity'?'LEGAL_ENTITY':'PAYOUT_ACCOUNT')->exists(),404);
        $r->validate(['approve'=>'required|boolean','reason'=>'required|string|max:2000']);
        $service->verify($r,$id,['approve'=>$r->boolean('approve'),'reason'=>$r->reason]);
        return $this->dto->version(DB::table('business_identity_versions')->find($id));
    }
}
