<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\{StaffProfile,StaffInvitation,Branch,Booking,User,RoleAssignment};
use App\Services\Beautybook\{ApiScope,ManagementPresenter};
use App\Services\Staff\StaffManagementService;
use App\Services\Identity\RoleAssignmentService;
use App\Enums\{RoleName,AccountKind};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash,Auth,Mail};
use Illuminate\Support\Str;

class ManagementStaffController extends Controller
{
    public function __construct(private ApiScope $scope,private ManagementPresenter $presenter,private StaffManagementService $staff) {}

    public function index(Request $request): array
    {
        $branchId=$request->input('branchId');
        $branch=$branchId ? $this->scope->branch($request,$branchId):null;
        $assignment=$this->scope->forRoles($request,['BUSINESS_OWNER','RECEPTIONIST','PLATFORM_ADMIN'],$branch?->business_id,$branch?->id);
        $grants=$this->scope->assignments($request);
        return StaffProfile::query()->when($assignment->role_name!=='PLATFORM_ADMIN',fn($q)=>$q->where('business_id',$assignment->business_id))
            ->when($branchId,fn($q)=>$q->where('branch_id',$branchId))
            ->when($assignment->role_name==='RECEPTIONIST',fn($q)=>$q->whereIn('branch_id',$grants->where('role_name','RECEPTIONIST')->pluck('branch_id')))
            ->orderBy('full_name')->get()->map(fn ($row)=>$this->presenter->staff($row))->all();
    }
    public function detail(Request $request,string $id): array
    {
        $staff=$id==='me' ? StaffProfile::where('user_id',$request->user()->id)->firstOrFail():StaffProfile::findOrFail($id);
        $this->scope->branch($request,$staff->branch_id);
        $assignment=$this->scope->forRoles($request,['BUSINESS_OWNER','RECEPTIONIST','STAFF','PLATFORM_ADMIN'],$staff->business_id,$staff->branch_id);
        if($assignment->role_name==='STAFF')abort_unless($staff->user_id===$request->user()->id,403);
        return $this->presenter->staff($staff);
    }
    public function save(Request $request,?string $id=null): array
    {
        $staff=$id ? StaffProfile::findOrFail($id):null;
        $business=$this->scope->owner($request,$staff?->business_id ?? $request->input('businessId'));
        $data=$request->validate(['branchId'=>[$id?'sometimes':'required','integer','exists:branches,id'],'fullName'=>[$id?'sometimes':'required','string','max:160'],'position'=>'nullable|string|max:120','bio'=>'nullable|string|max:4000','employeeCode'=>'nullable|string|max:80','experienceYears'=>'nullable|integer|min:0|max:80','publicVisible'=>'sometimes|boolean','isBookable'=>'sometimes|boolean','status'=>'sometimes|in:PROFILE_ONLY,INVITED,ACTIVE,LOCKED,INACTIVE','hiredAt'=>'nullable|date']);
        $snake=$staff?->getAttributes() ?? ['status'=>'PROFILE_ONLY','public_visible'=>false,'is_bookable'=>false];foreach($data as $key=>$value)$snake[Str::snake($key)]=$value;
        if($staff && ($snake['branch_id']!==$staff->branch_id || $snake['status']!=='ACTIVE' || !$snake['is_bookable']))abort_if($this->impactRows($staff)->isNotEmpty(),409,'Cần xử lý lịch tương lai trước khi thay đổi khả năng nhận lịch.');
        $saved=$staff ? $this->staff->update($staff,$snake):$this->staff->create($business,$snake);
        $this->audit($request,$saved,$id ? 'UPDATE':'CREATE',['changedFields'=>array_keys($data)]);
        return $this->presenter->staff($saved);
    }
    public function capabilities(Request $request,string $id): array
    {
        $staff=StaffProfile::findOrFail($id);$this->scope->branch($request,$staff->branch_id);
        if($request->isMethod('PATCH')){$this->scope->owner($request,$staff->business_id);$data=$request->validate(['serviceIds'=>'required|array','serviceIds.*'=>'integer|distinct']);$this->staff->replaceCapabilities($staff,$data['serviceIds']);$this->audit($request,$staff,'ASSIGN_SERVICES',['serviceIds'=>$data['serviceIds']]);}
        return $staff->offerings()->with('service')->get()->map(fn ($offering)=>$this->presenter->offering($offering))->all();
    }
    private function impactRows(StaffProfile $staff)
    {
        return Booking::whereHas('services',fn ($q)=>$q->where('staff_profile_id',$staff->id))->whereIn('status',['PENDING','CONFIRMED','CHECKED_IN','IN_PROGRESS'])->where('appointment_end_at','>',now()->utc())->orderBy('appointment_start_at')->get();
    }
    public function impact(Request $request,string $id): array
    {
        $staff=StaffProfile::findOrFail($id);$this->scope->owner($request,$staff->business_id);$rows=$this->impactRows($staff);
        return ['futureBookingCount'=>$rows->count(),'requiresReassignment'=>$rows->isNotEmpty(),'bookings'=>$rows->map(fn ($booking)=>['id'=>(string)$booking->id,'bookingCode'=>$booking->booking_code,'appointmentDate'=>$booking->appointment_start_at,'status'=>$booking->status,'branchId'=>(string)$booking->branch_id])->all()];
    }
    public function deactivate(Request $request,string $id): array
    {
        $staff=StaffProfile::findOrFail($id);$this->scope->owner($request,$staff->business_id);$request->validate(['reason'=>'required|string|max:2000']);
        return DB::transaction(function () use ($staff,$request) {
            Branch::whereKey($staff->branch_id)->lockForUpdate()->firstOrFail();
            \App\Models\Business::whereKey($staff->business_id)->lockForUpdate()->firstOrFail();
            $staff=StaffProfile::whereKey($staff->id)->lockForUpdate()->firstOrFail();$impact=$this->impact($request,(string)$staff->id);
            if($impact['requiresReassignment']){
                $case=app(\App\Services\Operations\ImpactService::class)->openForStaff($staff,$request->user()->id,$request->input('reason'));
                return ['deactivated'=>false,'requiresImpactResolution'=>true,'impactCase'=>$this->presenter->camel($case->toArray()) + ['subjectType'=>'STAFF','subjectId'=>(string)$staff->id,'action'=>'OFFBOARD'],'impact'=>$impact];
            }
            if($staff->user_id)foreach(RoleAssignment::where('user_id',$staff->user_id)->where('business_id',$staff->business_id)->where('branch_id',$staff->branch_id)->whereIn('role_name',['STAFF','RECEPTIONIST'])->get() as $assignment)app(RoleAssignmentService::class)->revoke($assignment);
            $this->staff->archive($staff);$this->audit($request,$staff,'OFFBOARD',['reason'=>$request->input('reason')]);return ['deactivated'=>true,'requiresImpactResolution'=>false,'staff'=>$this->presenter->staff($staff),'impact'=>$impact];
        });
    }
    public function assignBranch(Request $request,string $id): array
    {
        $staff=StaffProfile::findOrFail($id);$this->scope->owner($request,$staff->business_id);$data=$request->validate(['branchId'=>'required|integer|exists:branches,id','startDate'=>'required|date','isBookable'=>'sometimes|boolean','jobTitle'=>'nullable|string|max:120']);$branch=$this->scope->branch($request,$data['branchId']);
        return DB::transaction(function ()use($staff,$branch,$data){
            $staff=StaffProfile::whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $assignment=$staff->assignments()->updateOrCreate(['branch_id'=>$branch->id,'status'=>'ACTIVE'],['business_id'=>$staff->business_id,'start_date'=>$data['startDate'],'is_bookable'=>$data['isBookable'] ?? false,'is_primary'=>$staff->branch_id===$branch->id,'job_title'=>$data['jobTitle'] ?? $staff->position]);
            DB::table('audit_logs')->insert(['user_id'=>request()->user()->id,'business_id'=>$staff->business_id,'action'=>'ASSIGN_BRANCH','entity_type'=>'StaffProfile','entity_id'=>(string)$staff->id,'metadata'=>json_encode(['branchId'=>(string)$branch->id]),'created_at'=>now()]);
            return $this->presenter->camel($assignment->toArray());
        });
    }
    public function commission(Request $request,string $id): array
    {
        $staff=StaffProfile::findOrFail($id);$this->scope->branch($request,$staff->branch_id);$assignment=$this->scope->forRoles($request,['BUSINESS_OWNER','RECEPTIONIST','STAFF','PLATFORM_ADMIN'],$staff->business_id,$staff->branch_id);if($assignment->role_name==='STAFF')abort_unless($staff->user_id===$request->user()->id,403);
        $data=$request->validate(['startDate'=>'nullable|date','endDate'=>'nullable|date|after_or_equal:startDate']);
        $items=DB::table('booking_services')->join('bookings','bookings.id','=','booking_services.booking_id')->where('booking_services.staff_profile_id',$staff->id)->where('bookings.status','COMPLETED')->when($data['startDate'] ?? null,fn($q,$date)=>$q->where('appointment_start_at','>=',$date))->when($data['endDate'] ?? null,fn($q,$date)=>$q->where('appointment_start_at','<=',$date))->select('bookings.id as booking_id','bookings.booking_code','bookings.appointment_start_at as date','booking_services.service_name_snapshot as service_name','booking_services.price_at_booking as price')->get();
        return ['staffId'=>(string)$staff->id,'totalBookings'=>$items->count(),'totalRevenue'=>$items->sum('price'),'items'=>$items->map(fn ($row)=>$this->presenter->camel((array)$row))->all()];
    }
    public function calendar(Request $request,string $branchId): array
    {
        $branch=$this->scope->branch($request,$branchId);$this->scope->owner($request,$branch->business_id);
        $data=$request->validate(['date'=>'required|date_format:Y-m-d','name'=>'nullable|string|max:255','isClosed'=>'sometimes|boolean','startTime'=>'nullable|date_format:H:i','endTime'=>'nullable|date_format:H:i|after:startTime']);
        $closed=$request->isMethod('PATCH') ? ($data['isClosed'] ?? true):false;
        if(!$closed&&$request->isMethod('POST'))abort_unless(!empty($data['startTime'])&&!empty($data['endTime']),422);
        DB::table('beautybook_branch_calendar')->updateOrInsert(['branch_id'=>$branch->id,'date'=>$data['date']],['name'=>$data['name'] ?? null,'is_closed'=>$closed,'open_time'=>$closed ? null:($data['startTime'] ?? null),'close_time'=>$closed ? null:($data['endTime'] ?? null),'created_at'=>now(),'updated_at'=>now()]);
        $row=(array)DB::table('beautybook_branch_calendar')->where('branch_id',$branch->id)->where('date',$data['date'])->first();
        $row['is_closed']=(bool)$row['is_closed'];return $this->presenter->camel($row);
    }
    public function invitations(Request $request): array
    {
        $business=$this->scope->owner($request,$request->input('businessId'));return StaffInvitation::where('business_id',$business->id)->when($request->input('branchId'),fn($q,$id)=>$q->where('branch_id',$id))->latest()->get()->map(fn($row)=>$this->invitationDto($row))->all();
    }
    public function invite(Request $request): array
    {
        $business=$this->scope->owner($request,$request->input('businessId'));$data=$request->validate(['email'=>'required|email|max:255','roleCode'=>'required|in:STAFF,RECEPTIONIST','branchId'=>'required|integer|exists:branches,id','staffProfileId'=>'nullable|integer|exists:staff_profiles,id']);$this->scope->branch($request,$data['branchId']);
        $invite=$this->staff->invite($business,$request->user(),['email'=>$data['email'],'role_code'=>$data['roleCode'],'branch_id'=>$data['branchId'],'staff_profile_id'=>$data['staffProfileId'] ?? null]);
        if($invite->staff_profile_id)$this->audit($request,StaffProfile::findOrFail($invite->staff_profile_id),'INVITE',['invitationId'=>(string)$invite->id,'roleCode'=>$invite->role_code]);
        return $this->deliver($invite);
    }
    private function invitationDto(StaffInvitation $invite): array
    {
        return $this->presenter->camel(collect($invite->toArray())->except('token_hash')->all());
    }
    private function deliver(StaffInvitation $invite): array
    {
        $token=Str::random(64);$invite->forceFill(['token_hash'=>hash('sha256',$token),'status'=>'PENDING','expires_at'=>now()->addDays(7)])->save();
        $link=url('/beautybook/accept-invitation?token='.urlencode($token));
        Mail::raw('Bạn được mời tham gia '.$invite->business->name.'. Chấp nhận lời mời tại '.$link,fn($mail)=>$mail->to($invite->email)->subject('Lời mời tham gia BeautyBook'));
        return $this->invitationDto($invite)+['invitationUrl'=>$link];
    }
    public function invitationUpdate(Request $request,string $id,string $action): array
    {
        $invite=StaffInvitation::findOrFail($id);$this->scope->owner($request,$invite->business_id);abort_unless($invite->status==='PENDING',409);
        if($action==='revoke'){$invite->forceFill(['status'=>'REVOKED','revoked_at'=>now()])->save();return $this->invitationDto($invite);}
        if($action==='email'){$data=$request->validate(['email'=>'required|email|max:255']);$invite->email=mb_strtolower($data['email']);}
        return $this->deliver($invite);
    }
    private function byToken(string $token,bool $lock=false): StaffInvitation
    {
        $invite=StaffInvitation::where('token_hash',hash('sha256',$token))->when($lock,fn($q)=>$q->lockForUpdate())->firstOrFail();abort_unless($invite->status==='PENDING'&&$invite->expires_at->isFuture(),410,'Lời mời đã hết hạn hoặc không còn hiệu lực.');return $invite;
    }
    public function invitationContext(Request $request): array
    {
        $data=$request->validate(['token'=>'required|string|min:32|max:128']);$invite=$this->byToken($data['token']);
        return $this->invitationDto($invite)+['business'=>['id'=>(string)$invite->business_id,'name'=>$invite->business->name],'branch'=>['id'=>(string)$invite->branch_id,'name'=>$invite->branch?->name],'hasExistingAccount'=>User::where('email',$invite->email)->exists()];
    }
    public function acceptInvitation(Request $request): array
    {
        $existing=$request->is('*/accept-existing');$data=$request->validate(['token'=>'required|string|min:32|max:128','fullName'=>[$existing?'sometimes':'required','string','max:160'],'password'=>[$existing?'sometimes':'required','string','min:8']]);
        return DB::transaction(function ()use($request,$data,$existing){
            $invite=$this->byToken($data['token'],true);
            if($existing){$user=$request->user();abort_unless($user&&mb_strtolower($user->email)===mb_strtolower($invite->email)&&$user->account_kind===AccountKind::Operational,403);}
            else{abort_if(User::where('email',$invite->email)->exists(),409,'Email đã có tài khoản. Đăng nhập tài khoản doanh nghiệp để nhận lời mời.');$user=User::create(['name'=>$data['fullName'],'email'=>$invite->email,'password'=>$data['password'],'account_kind'=>AccountKind::Operational]);}
            $branch=Branch::findOrFail($invite->branch_id);abort_unless($branch->business_id===$invite->business_id,409);
            $role=RoleName::from($invite->role_code);$assignment=app(RoleAssignmentService::class)->grant($user,$role,$invite->business,$branch,$invite->inviter);
            if($invite->staff_profile_id){$profile=StaffProfile::whereKey($invite->staff_profile_id)->where('business_id',$invite->business_id)->lockForUpdate()->firstOrFail();abort_if($profile->user_id&&$profile->user_id!==$user->id,409);$profile->forceFill(['user_id'=>$user->id,'status'=>'ACTIVE'])->save();}
            $invite->forceFill(['status'=>'ACCEPTED','accepted_at'=>now(),'accepted_by'=>$user->id])->save();
            return ['accepted'=>true,'userId'=>(string)$user->id,'role'=>$assignment->role_name,'businessId'=>(string)$invite->business_id,'branchId'=>(string)$invite->branch_id];
        });
    }

    private function audit(Request $request,StaffProfile $staff,string $action,array $metadata=[]): void
    {
        DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'business_id'=>$staff->business_id,'action'=>$action,'entity_type'=>'StaffProfile','entity_id'=>(string)$staff->id,'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
}
