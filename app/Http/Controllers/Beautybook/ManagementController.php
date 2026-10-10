<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\{Business,Branch,BranchServiceOffering,BusinessService,MediaFile,RoleAssignment};
use App\Services\Beautybook\{ApiScope,ManagementPresenter};
use App\Services\Onboarding\BusinessOnboardingService;
use App\Services\Branches\BranchLifecycleService;
use App\Services\Platform\PlatformSettingsService;
use App\Services\Identity\SessionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ManagementController extends Controller
{
    public function __construct(private ApiScope $scope,private ManagementPresenter $presenter,private BusinessOnboardingService $businesses,private BranchLifecycleService $branches) {}

    public function businessConfig(Request $request,PlatformSettingsService $settings): array
    {
        abort_unless($request->user()->account_kind->value==='OPERATIONAL',403);
        $policy=$settings->get();
        return ['businessTypes'=>array_map(fn ($code,$label)=>compact('code','label'),['HAIR_SALON','SPA','NAIL','BARBER','MAKEUP','MASSAGE','BEAUTY_STUDIO','MOBILE_SERVICE'],['Salon tóc','Spa','Nail','Barber','Makeup','Massage','Beauty studio','Dịch vụ tận nơi']),
            'requiredDocuments'=>$policy['requireIdVerification'] ? ['BUSINESS_LICENSE','OWNER_ID_CARD']:[], 'requirePhoneVerification'=>$policy['requirePhoneVerification'],'phoneVerified'=>!$policy['requirePhoneVerification'] || (bool)$request->user()->phone_verified_at];
    }

    public function businessMine(Request $request)
    {
        abort_unless($request->user()->account_kind->value==='OPERATIONAL',403);
        $assignment=$this->scope->forRoles($request,['BUSINESS_OWNER']);
        $business=Business::find($assignment->business_id);
        return $business ? $this->presenter->business($business) : response()->json(null);
    }

    public function businessDetail(Request $request,string $id): array
    {
        $this->scope->platform($request);
        return $this->presenter->business(Business::findOrFail($id));
    }

    public function businessSave(Request $request,?string $id=null): array
    {
        $business=$id ? $this->scope->owner($request,$id):null;
        if(!$business){abort_unless($request->user()->account_kind->value==='OPERATIONAL',403);abort_if($request->user()->roleAssignments()->whereIn('role_name',['PLATFORM_ADMIN','STAFF','RECEPTIONIST'])->exists(),403);}
        $data=$request->validate(['name'=>'nullable|string|max:120','slug'=>['nullable','string','max:120','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',Rule::unique('businesses','slug')->ignore($id)],'description'=>'nullable|string|max:2000','contactEmail'=>'nullable|email|max:255','contactPhone'=>'nullable|string|max:32','addressLine'=>'nullable|string|max:255','companyName'=>'nullable|string|max:160','taxCode'=>'nullable|string|max:32','identityCardNumber'=>'nullable|string|max:100','legalRepresentative'=>'nullable|string|max:150','onboardingStep'=>'sometimes|integer|min:1|max:4','onboardingData'=>'sometimes|array','legalDocuments'=>'sometimes|array','legalDocuments.*.mediaId'=>'nullable|integer|exists:media_files,id']);
        return DB::transaction(function () use ($request,$business,$data) {
            $snake=[];foreach(['name','slug','description','contactEmail','contactPhone','addressLine','companyName','taxCode'] as $key)$snake[Str::snake($key)]=$data[$key] ?? ($business?->{Str::snake($key)});
            $snake['name']=$snake['name'] ?: 'Hồ sơ cơ sở của '.$request->user()->name;
            $snake['slug']=$snake['slug'] ?: 'business-'.Str::lower(Str::random(10));
            $business=$business ? $this->businesses->updateDraft($business,$snake):$this->businesses->create($request->user(),$snake);
            $this->settings('beautybook_business_settings','business_id',$business->id,'metadata',collect($data)->except('legalDocuments')->all());
            if(isset($data['legalDocuments']))foreach($data['legalDocuments'] as $document)if(!empty($document['mediaId']))$this->attachDocumentData($business->id,null,$document);
            app(SessionContext::class)->select($request,RoleAssignment::where('user_id',$request->user()->id)->where('role_name','BUSINESS_OWNER')->where('business_id',$business->id)->firstOrFail());
            return $this->presenter->business($business);
        });
    }

    public function businessSubmit(Request $request,string $id,PlatformSettingsService $settings): array
    {
        $business=$this->scope->owner($request,$id);
        if($settings->value('requireIdVerification')){
            $types=DB::table('beautybook_documents')->where('business_id',$id)->whereNull('branch_id')->where('status','ACTIVE')->pluck('document_type');
            abort_unless($types->contains('BUSINESS_LICENSE')&&$types->contains('OWNER_ID_CARD'),422,'Cần tải giấy phép kinh doanh và giấy tờ người đại diện.');
        }
        return $this->presenter->business($this->businesses->submit($business,$request->user()));
    }

    public function businessReview(Request $request,string $id): array
    {
        $this->scope->platform($request);$data=$request->validate(['decision'=>'required|in:APPROVE,REQUEST_INFO,REJECT','note'=>'nullable|string|max:2000']);
        return $this->presenter->business($this->businesses->review(Business::findOrFail($id),$request->user(),$data['decision'],$data['note'] ?? null));
    }

    public function members(Request $request,string $id): array
    {
        $this->scope->owner($request,$id);return RoleAssignment::with('user')->where('business_id',$id)->get()->map(fn ($assignment)=>['id'=>(string)$assignment->id,'role'=>$assignment->role_name,'businessId'=>(string)$id,'branchId'=>$assignment->branch_id ? (string)$assignment->branch_id:null,'user'=>['id'=>(string)$assignment->user_id,'fullName'=>$assignment->user->name,'email'=>$assignment->user->email]])->all();
    }

    public function cancellation(Request $request,string $id): array
    {
        $this->scope->owner($request,$id);
        if($request->isMethod('PATCH')){
            abort_if(array_diff(array_keys($request->all()),['freeCancelHours','rescheduleAllowedHours','notes'])!==[],422,'Chính sách chứa trường không được phép cập nhật.');
            $data=$request->validate(['freeCancelHours'=>'sometimes|integer|in:4','rescheduleAllowedHours'=>'sometimes|integer|min:0|max:2147483647','notes'=>'nullable|string|max:2000']);
            $this->settings('beautybook_business_settings','business_id',(int)$id,'cancellation_policy',$data);
        }
        return array_replace(['businessId'=>(string)$id,'freeCancelHours'=>4,'rescheduleAllowedHours'=>4,'notes'=>null],json_decode(DB::table('beautybook_business_settings')->where('business_id',$id)->value('cancellation_policy') ?? '{}',true),['freeCancelHours'=>4]);
    }

    public function branchList(Request $request): array
    {
        $assignment=$this->scope->current($request);abort_unless($assignment->role_name!=='CUSTOMER',403);
        $query=Branch::query()->when($assignment->business_id,fn ($q)=>$q->where('business_id',$assignment->business_id))->when(in_array($assignment->role_name,['STAFF','RECEPTIONIST']),fn ($q)=>$q->whereKey($assignment->branch_id));
        return $query->orderBy('name')->get()->map(fn ($branch)=>$this->presenter->branch($branch))->all();
    }

    public function branchDetail(Request $request,string $id): array { return $this->presenter->branch($this->scope->branch($request,$id)); }

    public function branchCreate(Request $request): array
    {
        $data=$request->validate(['businessId'=>'required|integer|exists:businesses,id','sameLegalEntity'=>'sometimes|boolean','name'=>'nullable|string|max:120','serviceMode'=>'sometimes|in:AT_LOCATION,MOBILE,BOTH']);
        $business=$this->scope->owner($request,$data['businessId']);
        if(isset($data['sameLegalEntity'])&&!$data['sameLegalEntity'])return ['requiresNewBusiness'=>true,'redirectTo'=>'/register/business'];
        return DB::transaction(function ()use($business,$data){
            $business=Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($business->status->value,['APPROVED','ACTIVE'],true)&&!$business->booking_restricted_at,403,'Doanh nghiệp phải được duyệt và không bị hạn chế trước khi tạo chi nhánh.');
            $limit=app(PlatformSettingsService::class)->value('maxBranchesPerBusiness');abort_if($business->branches()->count()>=$limit,409,'Đã đạt giới hạn chi nhánh.');
            $name=$data['name'] ?? 'Chi nhánh mới của '.$business->name;
            $branch=$this->branches->create($business->id,['name'=>$name,'slug'=>Str::slug($name).'-'.Str::lower(Str::random(8))]);
            $this->settings('beautybook_branch_settings','branch_id',$branch->id,'metadata',['serviceMode'=>$data['serviceMode'] ?? 'AT_LOCATION','sameLegalEntity'=>true]);
            return ['created'=>true,'requiresNewBusiness'=>false,'branch'=>$this->presenter->branch($branch)];
        },3);
    }

    public function branchSave(Request $request,string $id): array
    {
        $branch=$this->scope->branch($request,$id);$this->scope->owner($request,$branch->business_id);
        $payload=$request->is('*/onboarding') ? $request->input('branch',[]):$request->all();
        $data=Validator::make($payload,['name'=>'sometimes|string|max:120','publicName'=>'sometimes|string|max:160','phone'=>'nullable|string|max:32','email'=>'nullable|email|max:255','description'=>'nullable|string|max:2000','addressLine'=>'nullable|string|max:255','timezone'=>'sometimes|in:Asia/Ho_Chi_Minh','managerName'=>'nullable|string|max:160','scheduledOpeningDate'=>'nullable|date','districtId'=>'nullable|string|max:80','ward'=>'nullable|string|max:160','floor'=>'nullable|string|max:80','directions'=>'nullable|string|max:2000','serviceMode'=>'sometimes|in:AT_LOCATION,MOBILE,BOTH','serviceAreas'=>'sometimes|array','excludedServiceAreas'=>'sometimes|array','serviceRadiusKm'=>'nullable|numeric|min:0|max:1000','travelFee'=>'sometimes|numeric|min:0','bookingStartDate'=>'nullable|date','bookingConfirmationMode'=>'sometimes|in:MANUAL_CONFIRMATION,AUTO_CONFIRMATION','staffAssignmentMode'=>'sometimes|in:CUSTOMER_SELECTS_STAFF,AUTO_ASSIGN_IF_ANY_STAFF,MANUAL_ASSIGN_BY_RECEPTIONIST','pendingHoldMinutes'=>'sometimes|integer|min:5|max:1440'])->validate();
        return DB::transaction(function () use ($request,$branch,$data) {
            $branch=Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $snake=[];foreach(['name','publicName','phone','email','description','addressLine','timezone'] as $key)$snake[Str::snake($key)]=$data[$key] ?? $branch->{Str::snake($key)};
            $snake['slug']=$branch->slug;$snake['public_name']=$snake['public_name'] ?: $snake['name'];
            if($branch->review_status->value==='APPROVED')$branch->fill($snake)->save();
            else $this->branches->updateDraft($branch,$snake);
            $this->settings('beautybook_branch_settings','branch_id',$branch->id,'metadata',$data);
            if($request->exists('currentStep')){
                $draft=$request->validate(['currentStep'=>'required|integer|min:1|max:13','completedSteps'=>'sometimes|array','completedSteps.*'=>'integer|min:1|max:13','draftData'=>'sometimes|array']);
                $this->settings('beautybook_branch_settings','branch_id',$branch->id,'onboarding',$draft);
            }
            if($request->exists('bookingPolicy')){
                $policy=$request->validate(['bookingPolicy'=>'required|array','bookingPolicy.leadTimeMinutes'=>'sometimes|integer|min:0|max:10080','bookingPolicy.bookingHorizonDays'=>'sometimes|integer|min:1|max:365','bookingPolicy.cancellationHours'=>'sometimes|integer|min:0|max:168','bookingPolicy.rescheduleHours'=>'sometimes|integer|min:0|max:168','bookingPolicy.earlyCheckInMinutes'=>'sometimes|integer|min:0|max:240','bookingPolicy.gracePeriodMinutes'=>'sometimes|integer|min:0|max:240','bookingPolicy.defaultBufferMinutes'=>'sometimes|integer|min:0|max:240','bookingPolicy.overbookingEnabled'=>'sometimes|boolean','bookingPolicy.maxOverbookedSlots'=>'sometimes|integer|min:0|max:20','bookingPolicy.allowWalkIn'=>'sometimes|boolean','bookingPolicy.allowCounterBooking'=>'sometimes|boolean','bookingPolicy.noShowHandling'=>'sometimes|string|max:80'])['bookingPolicy'];
                $this->settings('beautybook_branch_settings','branch_id',$branch->id,'booking_policy',$policy+['confirmedAt'=>now()->toIso8601String()]);
            }
            if($request->exists('workingHours')){
                $hours=$request->validate(['workingHours'=>'required|array|size:7','workingHours.*.dayOfWeek'=>'required|integer|min:0|max:6|distinct','workingHours.*.isClosed'=>'required|boolean','workingHours.*.openTime'=>'nullable|date_format:H:i','workingHours.*.closeTime'=>'nullable|date_format:H:i'])['workingHours'];
                $this->branches->replaceHours($branch,array_map(fn ($row)=>['day_of_week'=>$row['dayOfWeek'],'is_closed'=>$row['isClosed'],'open_time'=>$row['openTime'] ?? null,'close_time'=>$row['closeTime'] ?? null],$hours));
            }
            return $this->presenter->branch($branch->refresh());
        });
    }

    public function branchTransition(Request $request,string $id,string $action): array
    {
        $branch=$this->scope->branch($request,$id);$this->scope->owner($request,$branch->business_id);
        if($action==='status'){
            $data=$request->validate(['status'=>'required|in:ACTIVE,INACTIVE,PAUSED,CLOSED','reason'=>'required|string|max:2000']);
            $action=match($data['status']){'ACTIVE'=>'restore','CLOSED'=>'close',default=>'pause'};
            $branch=$this->branches->$action($branch,$request->user(),$data['reason']);
        }else {
            abort_unless(in_array($action,['submit','publish']),404);
            $presentation=$this->presenter->branch($branch);
            if($action==='publish')abort_unless($presentation['readiness']['ready'],409,implode(', ',$presentation['readiness']['reasons']));
            if($action==='submit')abort_if(($presentation['serviceMode'] ?? 'AT_LOCATION')!=='MOBILE' && (empty($presentation['addressLine'])||empty($presentation['districtId'])),422,'Cần hoàn tất địa chỉ và quận/huyện.');
            $branch=$this->branches->$action($branch,$request->user());
        }
        return $this->presenter->branch($branch);
    }

    public function copyServices(Request $request,string $id): array
    {
        $branch=$this->scope->branch($request,$id);$this->scope->owner($request,$branch->business_id);
        $data=$request->validate(['sourceBranchId'=>'required|integer|different:id','serviceIds'=>'required|array|min:1','serviceIds.*'=>'integer|distinct']);$source=$this->scope->branch($request,$data['sourceBranchId']);
        abort_unless($source->business_id===$branch->business_id && $source->id!==$branch->id,422);
        DB::transaction(function () use ($branch,$source,$data){
            $offerings=BranchServiceOffering::where('branch_id',$source->id)->whereIn('id',$data['serviceIds'])->with('variants')->get();abort_unless($offerings->count()===count($data['serviceIds']),422);
            foreach($offerings as $offering){
                $new=BranchServiceOffering::firstOrCreate(['branch_id'=>$branch->id,'business_service_id'=>$offering->business_service_id],['business_id'=>$branch->business_id,'price'=>$offering->price,'duration_minutes'=>$offering->duration_minutes,'bookable'=>false,'is_active'=>false]);
                if($new->wasRecentlyCreated)foreach($offering->variants as $variant)$new->variants()->create(collect($variant->getAttributes())->except(['id','branch_service_offering_id','created_at','updated_at','deleted_at'])->all());
            }
        });return $this->presenter->branch($branch->refresh());
    }

    public function document(Request $request,string $id,?string $documentId=null)
    {
        $branch=$this->scope->branch($request,$id);$this->scope->owner($request,$branch->business_id);
        abort_unless(in_array($branch->review_status->value,['DRAFT','NEED_MORE_INFO']),409,'Không thể thay đổi tài liệu khi hồ sơ đang xét duyệt hoặc đã duyệt.');
        if($documentId){abort_unless(DB::table('beautybook_documents')->where('id',$documentId)->where('branch_id',$branch->id)->exists(),404);DB::table('beautybook_documents')->where('id',$documentId)->update(['status'=>'ARCHIVED','updated_at'=>now()]);return response()->noContent();}
        $data=$request->validate(['mediaId'=>'required|integer|exists:media_files,id','documentType'=>'required|string|max:80','documentName'=>'required|string|max:255','documentNumber'=>'nullable|string|max:255','issuedAt'=>'nullable|date','expiresAt'=>'nullable|date','replaceDocumentId'=>'nullable|integer']);
        $this->attachDocumentData($branch->business_id,$branch->id,$data);return $this->presenter->documents($branch->business_id,$branch->id);
    }

    private function attachDocumentData(int $businessId,?int $branchId,array $data): void
    {
        DB::transaction(function () use($businessId,$branchId,$data){
            $media=MediaFile::findOrFail($data['mediaId']);abort_unless($media->business_id===$businessId&&$media->branch_id===$branchId&&$media->visibility==='PRIVATE',422,'Tài liệu cần media riêng tư trong đúng phạm vi.');
            if(!empty($data['replaceDocumentId'])){ $doc=DB::table('beautybook_documents')->where('id',$data['replaceDocumentId'])->where('business_id',$businessId)->where('branch_id',$branchId)->lockForUpdate()->first();abort_unless($doc,404);$id=$doc->id; }
            else {
                $existing=DB::table('beautybook_document_versions')->join('beautybook_documents','beautybook_documents.id','=','beautybook_document_versions.document_id')->where('beautybook_documents.business_id',$businessId)->where('beautybook_documents.branch_id',$branchId)->where('media_id',$media->id)->exists(); if($existing)return;
                $id=DB::table('beautybook_documents')->insertGetId(['business_id'=>$businessId,'branch_id'=>$branchId,'document_type'=>$data['documentType'],'document_name'=>$data['documentName'] ?? $media->original_name,'document_number'=>$data['documentNumber'] ?? null,'issued_at'=>$data['issuedAt'] ?? null,'expires_at'=>$data['expiresAt'] ?? null,'created_at'=>now(),'updated_at'=>now()]);
            }
            $version=(int)DB::table('beautybook_document_versions')->where('document_id',$id)->max('version')+1;
            DB::table('beautybook_document_versions')->insert(['document_id'=>$id,'media_id'=>$media->id,'version'=>$version,'created_at'=>now(),'updated_at'=>now()]);
        });
    }

    private function settings(string $table,string $key,int $id,string $column,array $data): void
    {
        DB::table($table)->insertOrIgnore([$key=>$id,'created_at'=>now(),'updated_at'=>now()]);
        $row=DB::table($table)->where($key,$id)->lockForUpdate()->first();
        DB::table($table)->where($key,$id)->update([$column=>json_encode(array_replace(json_decode($row->$column ?? '{}',true),$data),JSON_THROW_ON_ERROR),'updated_at'=>now()]);
    }
}
