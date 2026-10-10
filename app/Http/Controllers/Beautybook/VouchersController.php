<?php
namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Services\Beautybook\{ApiScope,SourceVoucherService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VouchersController extends Controller
{
    public function __construct(private ApiScope $scope,private SourceVoucherService $vouchers) {}
    public function mine(Request $request): array {return $this->vouchers->mine($this->scope->customer($request));}
    public function index(Request $request): array {
        $assignment=$this->manager($request);$request->validate(['status'=>'nullable|in:ACTIVE,EXPIRED,REVOKED']);
        return Voucher::when($assignment->role_name!=='PLATFORM_ADMIN',fn($q)=>$q->where('business_id',$assignment->business_id))->when($request->filled('status'),fn($q)=>$q->where('status',$request->input('status')))->latest()->get()->map(fn($v)=>$this->vouchers->dto($v))->all();
    }
    public function save(Request $request,?string $id=null) {
        $assignment=$this->manager($request);$existing=$id?$this->owned($request,$id):null;$creating=!$id;
        if($assignment->role_name==='PLATFORM_ADMIN'){abort_if($request->filled('businessId'),403);$business=null;}
        else {$request->validate(['businessId'=>[$creating?'required':'sometimes','integer']]);$business=$this->scope->owner($request,$existing?->business_id ?? $request->input('businessId'));}
        $rules=['name'=>[$creating?'required':'sometimes','string','max:200'],'description'=>'nullable|string|max:2000','totalQuantity'=>[$creating?'required':'sometimes','integer','min:1'],'endDate'=>[$creating?'required':'sometimes','date'],'status'=>'sometimes|in:ACTIVE,EXPIRED,REVOKED','audience'=>'sometimes|in:ALL,NEW_CUSTOMER,RETURNING_CUSTOMER,BIRTHDAY,VIP,SELECTED','autoIssue'=>'sometimes|boolean','maxUsagePerCustomer'=>'sometimes|integer|min:1','branchIds'=>'sometimes|array','branchIds.*'=>'integer|distinct','serviceIds'=>'sometimes|array','serviceIds.*'=>'integer|distinct','comboIds'=>'sometimes|array','comboIds.*'=>'integer|distinct'];
        if($creating)$rules+=['code'=>['required','string','max:50',Rule::unique('vouchers','code')],'discountType'=>'required|in:PERCENTAGE,FIXED_AMOUNT','discountValue'=>'required|decimal:0,2|gt:0','minOrderValue'=>'sometimes|decimal:0,2|min:0','maxDiscount'=>'nullable|decimal:0,2|gt:0','startDate'=>'required|date','scope'=>'sometimes|in:PLATFORM,TENANT,CUSTOMER,COMPENSATION,CAMPAIGN'];
        if($creating && $request->has('code'))$request->merge(['code'=>strtoupper(trim($request->input('code')))]);
        $validated=$request->validate($rules);$data=[];foreach($validated as $key=>$value)$data[Str::snake($key)]=$value;
        try{$voucher=DB::transaction(function()use($business,$data,$existing,$request,$creating){$saved=$this->vouchers->save($business,$data,$existing);$this->audit($request,$saved,$creating?'CREATE':'UPDATE');return $saved;},3);}catch(\DomainException $error){$message=$error->getMessage();abort(str_contains($message,'Phạm vi') || str_contains($message,'Combo')?403:(str_contains($message,'quota') || str_contains($message,'Giới hạn')?409:422),$message);}
        return response()->json($this->vouchers->dto($voucher),$creating?201:200);
    }
    public function grant(Request $request,string $id): array {
        $voucher=$this->owned($request,$id);$data=$request->validate(['customerId'=>'required|string|max:255']);
        return $this->vouchers->grant($voucher,$data['customerId'],$request->user()->id);
    }
    public function remove(Request $request,string $id): array {
        $voucher=$this->owned($request,$id);
        DB::transaction(function()use($voucher,$request){$locked=Voucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();$locked->forceFill(['status'=>'REVOKED','version'=>$locked->version+1])->save();$locked->delete();$this->audit($request,$locked,'DELETE');});
        return $this->vouchers->dto(Voucher::withTrashed()->findOrFail($voucher->id));
    }
    private function manager(Request $request) {return $this->scope->forRoles($request,['BUSINESS_OWNER','PLATFORM_ADMIN']);}
    private function audit(Request $request,Voucher $voucher,string $action): void {DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'business_id'=>$voucher->business_id,'action'=>$action,'entity_type'=>'Voucher','entity_id'=>(string)$voucher->id,'metadata'=>json_encode(['version'=>$voucher->version]),'created_at'=>now()]);}
    private function owned(Request $request,string $id): Voucher {
        $assignment=$this->manager($request);$voucher=Voucher::findOrFail($id);
        if($assignment->role_name==='PLATFORM_ADMIN')abort_unless(!$voucher->business_id && $voucher->created_by_platform,403);
        else {abort_unless(!$voucher->created_by_platform && $voucher->business_id,403);$this->scope->owner($request,$voucher->business_id);}
        return $voucher;
    }
}
