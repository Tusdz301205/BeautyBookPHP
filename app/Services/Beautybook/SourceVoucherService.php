<?php
namespace App\Services\Beautybook;

use App\Models\{Voucher,Business,Branch,BranchServiceOffering,Combo,CustomerProfile,Booking};
use App\Services\Promotions\PromotionManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SourceVoucherService
{
    public function __construct(private PromotionManagementService $promotions) {}

    public function dto(Voucher $voucher): array
    {
        $business=$voucher->business_id ? Business::find($voucher->business_id):null;
        $branches=Branch::whereIn('id',$voucher->branch_ids ?? [])->get()->map(fn($row)=>['id'=>(string)$row->id,'name'=>$row->name])->all();
        $services=BranchServiceOffering::withTrashed()->with(['service'=>fn($query)=>$query->withTrashed()])->whereIn('id',$voucher->service_ids ?? [])->get()->map(fn($row)=>['id'=>(string)$row->id,'name'=>$row->service?->name ?? 'Dịch vụ đã lưu trữ'])->all();
        $combos=Combo::withTrashed()->whereIn('id',$voucher->combo_ids ?? [])->get()->map(fn($row)=>['id'=>(string)$row->id,'name'=>$row->name])->all();
        return ['id'=>(string)$voucher->id,'code'=>$voucher->code,'name'=>$voucher->name,'description'=>$voucher->description,'discountType'=>$voucher->discount_type,'discountValue'=>(float)$voucher->discount_value,'minOrderValue'=>(float)$voucher->min_order_value,'maxDiscount'=>$voucher->max_discount===null?null:(float)$voucher->max_discount,'totalQuantity'=>$voucher->total_quantity,'usedQuantity'=>$voucher->used_quantity,'remaining'=>$voucher->total_quantity-$voucher->used_quantity,'startDate'=>$voucher->start_date,'endDate'=>$voucher->end_date,'status'=>$voucher->status,'claimedCount'=>DB::table('customer_vouchers')->where('voucher_id',$voucher->id)->count(),'usedInBookings'=>DB::table('voucher_redemptions')->where('voucher_id',$voucher->id)->count(),'audience'=>$voucher->audience,'autoIssue'=>$voucher->auto_issue,'maxUsagePerCustomer'=>$voucher->max_usage_per_customer,'branches'=>$branches,'services'=>$services,'combos'=>$combos,'version'=>$voucher->version,'scope'=>$voucher->scope,'businessId'=>$business?(string)$business->id:null,'business'=>$business?['id'=>(string)$business->id,'name'=>$business->name,'slug'=>$business->slug]:null];
    }

    public function save(?Business $business,array $data,?Voucher $existing=null): Voucher
    {
        return DB::transaction(function()use($business,$data,$existing){
            $scope=$existing?->scope ?? ($data['scope'] ?? ($business?'TENANT':'PLATFORM'));
            abort_if(($business && $scope==='PLATFORM') || (!$business && $scope==='TENANT'),403,'Phạm vi voucher không khớp quyền sở hữu');
            $saved=$this->promotions->save($business,'voucher',$data,$existing?->id);
            if($saved->scope!==$scope)$saved->forceFill(['scope'=>$scope])->save();
            return $saved;
        },3);
    }

    public function grant(Voucher $voucher,string $reference,int $actorId): array
    {
        return DB::transaction(function()use($voucher,$reference,$actorId){
            $voucher=Voucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            abort_unless($voucher->status==='ACTIVE' && !$voucher->end_date->isPast(),422,'Voucher không còn hoạt động');
            abort_if($voucher->used_quantity >= $voucher->total_quantity,409,'Voucher đã hết lượt sử dụng');
            $customer=$this->resolveCustomer(trim($reference),$voucher->business_id);
            abort_if(DB::table('customer_vouchers')->where('voucher_id',$voucher->id)->where('customer_profile_id',$customer->id)->lockForUpdate()->exists(),422,'Khách hàng đã có voucher này');
            if($voucher->audience==='SELECTED' && $voucher->business_id){
                $key=['business_id'=>$voucher->business_id,'customer_profile_id'=>$customer->id,'segment'=>'SELECTED'];
                DB::table('customer_business_segments')->insertOrIgnore($key+['assigned_by_user_id'=>$actorId,'created_at'=>now(),'updated_at'=>now()]);
                DB::table('customer_business_segments')->where($key)->update(['assigned_by_user_id'=>$actorId,'updated_at'=>now()]);
            }
            $id=DB::table('customer_vouchers')->insertGetId(['voucher_id'=>$voucher->id,'customer_profile_id'=>$customer->id,'status'=>'ACTIVE','expires_at'=>$voucher->end_date,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('audit_logs')->insert(['user_id'=>$actorId,'business_id'=>$voucher->business_id,'action'=>'CREATE','entity_type'=>'CustomerVoucher','entity_id'=>(string)$id,'metadata'=>json_encode(['voucherId'=>(string)$voucher->id,'customerId'=>(string)$customer->id]),'created_at'=>now()]);
            return ['id'=>(string)$id,'voucherId'=>(string)$voucher->id,'customerId'=>(string)$customer->id,'status'=>'ACTIVE','acquiredAt'=>now(),'expiresAt'=>$voucher->end_date,'usedAt'=>null,'usedBookingId'=>null];
        },3);
    }

    private function resolveCustomer(string $reference,?int $businessId): CustomerProfile
    {
        $query=CustomerProfile::query();
        if($businessId)$query->whereHas('bookings',fn($bookings)=>$bookings->where('business_id',$businessId));
        if(ctype_digit($reference))$query->where(function($identity)use($reference){$identity->whereKey((int)$reference)->orWhereHas('user',fn($users)=>$users->whereIn('id',DB::table('beautybook_user_profiles')->where('phone',$reference)->select('user_id')));});
        elseif(Str::isUuid($reference))$query->where('beautybook_reference',$reference);
        elseif(preg_match('/^customer-(\d+)$/',$reference,$match))$query->whereKey((int)$match[1]);
        else $query->whereHas('user',function($users)use($reference){$users->where(function($identity)use($reference){$identity->whereRaw('LOWER(email) = ?',[mb_strtolower($reference)])->orWhereRaw('LOWER(name) = ?',[mb_strtolower($reference)])->orWhereIn('id',DB::table('beautybook_user_profiles')->where('phone',$reference)->select('user_id'));});});
        $matches=$query->limit(2)->get();
        // Tenant-only search gives the same response for absent and unrelated identities.
        abort_if($matches->count()!==1,422,$matches->count()>1?'Có nhiều khách hàng trùng tên; hãy dùng email hoặc số điện thoại':'Không tìm thấy khách hàng có quan hệ với doanh nghiệp');
        return CustomerProfile::whereKey($matches[0]->id)->lockForUpdate()->firstOrFail();
    }

    public function mine(CustomerProfile $customer): array
    {
        $this->issueAutomatic($customer);
        return DB::table('customer_vouchers')->where('customer_profile_id',$customer->id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(function($assignment){
            $voucher=Voucher::withTrashed()->findOrFail($assignment->voucher_id);
            $expires=$assignment->expires_at && Carbon::parse($assignment->expires_at)->lt($voucher->end_date)?Carbon::parse($assignment->expires_at):$voucher->end_date;
            $applied=DB::table('voucher_redemptions')->where('voucher_id',$voucher->id)->where('customer_profile_id',$assignment->customer_profile_id)->where('status','APPLIED')->orderByDesc('applied_at')->first();
            $used=$assignment->status==='USED' || $applied;
            $revoked=$assignment->status==='REVOKED' || $voucher->status==='REVOKED' || $voucher->trashed();
            $status=$used?'USED':($revoked?'REVOKED':($expires->isPast() || $voucher->status==='EXPIRED'?'EXPIRED':$assignment->status));
            $dto=$this->dto($voucher);
            return ['id'=>(string)$assignment->id,'status'=>$status,'acquiredAt'=>Carbon::parse($assignment->created_at),'usedAt'=>$applied?->applied_at,'usedBookingId'=>$applied?(string)$applied->booking_id:null,'expiresAt'=>$expires,'isUsable'=>$status==='ACTIVE' && $voucher->status==='ACTIVE' && !$voucher->start_date->isFuture(),'voucher'=>array_intersect_key($dto,array_flip(['id','code','name','description','discountType','discountValue','minOrderValue','maxDiscount','startDate','endDate','scope','business']))];
        })->all();
    }

    private function issueAutomatic(CustomerProfile $customer): void
    {
        $bookings=$customer->bookings()->get(['business_id','status']);
        $segments=DB::table('customer_business_segments')->where('customer_profile_id',$customer->id)->get();
        $related=$bookings->pluck('business_id')->merge($segments->pluck('business_id'))->unique()->all();
        $completed=$bookings->where('status','COMPLETED')->pluck('business_id')->all();
        $birthday=DB::table('beautybook_user_profiles')->where('user_id',$customer->user_id)->value('date_of_birth') ?? $customer->date_of_birth;
        $candidates=Voucher::where('auto_issue',true)->where('status','ACTIVE')->where('start_date','<=',now())->where('end_date','>=',now())->where(fn($q)=>$q->whereIn('business_id',$related)->orWhere(fn($platform)=>$platform->whereNull('business_id')->where('created_by_platform',true)))->get();
        foreach($candidates as $candidate)DB::transaction(function()use($candidate,$customer,$completed,$segments,$birthday){
            $voucher=Voucher::whereKey($candidate->id)->lockForUpdate()->first();
            if(!$voucher || $voucher->status!=='ACTIVE' || $voucher->start_date->isFuture() || $voucher->end_date->isPast() || $voucher->used_quantity >= $voucher->total_quantity)return;
            $eligible=match($voucher->audience){'ALL'=>true,'NEW_CUSTOMER'=>!$voucher->business_id || !in_array($voucher->business_id,$completed),'RETURNING_CUSTOMER'=>$voucher->business_id && in_array($voucher->business_id,$completed),'VIP','SELECTED'=>$voucher->business_id && $segments->where('business_id',$voucher->business_id)->where('segment',$voucher->audience)->isNotEmpty(),'BIRTHDAY'=>$birthday && Carbon::parse($birthday)->format('m-d')===now('UTC')->format('m-d'),default=>false};
            if($eligible)DB::table('customer_vouchers')->insertOrIgnore(['voucher_id'=>$voucher->id,'customer_profile_id'=>$customer->id,'status'=>'ACTIVE','expires_at'=>$voucher->end_date,'created_at'=>now(),'updated_at'=>now()]);
        },3);
    }
}
