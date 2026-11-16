<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\{Branch,BranchServiceOffering,Promotion,Combo};
use App\Services\Beautybook\{ApiScope,ManagementPresenter};
use App\Services\Promotions\PromotionManagementService;
use App\Services\Scheduling\ComboService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManagementOffersController extends Controller
{
    public function __construct(private ApiScope $scope,private ManagementPresenter $presenter) {}
    private function promotionDto(Promotion $promotion): array
    {
        return $this->presenter->camel($promotion->toArray())+['businesses'=>$promotion->business_id ? [['id'=>(string)$promotion->business_id]]:[],
            'branches'=>Branch::whereIn('id',$promotion->branch_ids ?? [])->get()->map(fn ($branch)=>['id'=>(string)$branch->id,'name'=>$branch->name])->all(),
            'services'=>BranchServiceOffering::whereIn('id',$promotion->service_ids ?? [])->with('service')->get()->map(fn($service)=>['id'=>(string)$service->id,'name'=>$service->service->name])->all(),
            'combos'=>Combo::whereIn('id',$promotion->combo_ids ?? [])->get()->map(fn($combo)=>['id'=>(string)$combo->id,'name'=>$combo->name])->all()];
    }
    public function promotions(Request $request): array
    {
        $assignment=$this->scope->forRoles($request,['BUSINESS_OWNER','RECEPTIONIST','PLATFORM_ADMIN']);
        return Promotion::when($assignment->role_name!=='PLATFORM_ADMIN',fn($q)=>$q->where('business_id',$assignment->business_id))->when($request->input('status'),fn($q,$status)=>$q->where('status',$status))->latest()->get()->map(fn($row)=>$this->promotionDto($row))->all();
    }
    public function savePromotion(Request $request,PromotionManagementService $service,?string $id=null): array
    {
        $assignment=$this->scope->current($request);$business=$assignment->role_name==='PLATFORM_ADMIN' ? null:$this->scope->owner($request);
        if(!$business)$this->scope->platform($request);
        $r=$id ? 'sometimes':'required';
        $data=$request->validate(['name'=>[$r,'string','max:255'],'description'=>'nullable|string|max:3000','discountType'=>[$r,'in:PERCENTAGE,FIXED_AMOUNT'],'discountValue'=>[$r,'numeric','gt:0','max:9999999999.99'],'startDate'=>[$r,'date'],'endDate'=>[$r,'date'],'status'=>'sometimes|in:ACTIVE,INACTIVE,EXPIRED','audience'=>'sometimes|in:ALL,NEW_CUSTOMER,RETURNING_CUSTOMER,BIRTHDAY,VIP,SELECTED','totalQuantity'=>'nullable|integer|min:1','maxUsagePerCustomer'=>'sometimes|integer|min:1','branchIds'=>'sometimes|array','branchIds.*'=>'integer|distinct','serviceIds'=>'sometimes|array','serviceIds.*'=>'integer|distinct','comboIds'=>'sometimes|array','comboIds.*'=>'integer|distinct','autoApply'=>'sometimes|boolean','stackingAllowed'=>'sometimes|boolean','businessIds'=>'sometimes|array','businessIds.*'=>'integer|distinct']);
        $data += $request->validate(['code'=>'sometimes|nullable|string|max:32','targetMode'=>'sometimes|in:ALL,SELECTED,LEGACY']);
        if(isset($data['businessIds'])){abort_unless(($business && array_map('intval',$data['businessIds'])===[$business->id])||(!$business&&!$data['businessIds']),403);unset($data['businessIds']);}
        $snake=[];foreach($data as $key=>$value)$snake[Str::snake($key)]=$value;
        return $this->promotionDto($service->save($business,'promotion',$snake,$id ? (int)$id:null));
    }
    public function removePromotion(Request $request,string $id)
    {
        $promotion=Promotion::findOrFail($id);$assignment=$this->scope->current($request);
        if($promotion->created_by_platform)$this->scope->platform($request);else$this->scope->owner($request,$promotion->business_id);
        DB::transaction(function ()use($promotion){$promotion=Promotion::whereKey($promotion->id)->lockForUpdate()->firstOrFail();$promotion->status='INACTIVE';$promotion->version++;$promotion->save();$promotion->delete();});return response()->noContent();
    }
    public function publicPromotions(Request $request,string $serviceId): array
    {
        $offering=BranchServiceOffering::whereKey($serviceId)->whereHas('branch',fn($q)=>$q->publicReady())->firstOrFail();
        return $this->publicPromotionRows($request,$offering->branch,[$offering->id]);
    }
    public function publicComboPromotions(Request $request,string $comboId,ComboService $service):array
    {
        $combo=$service->publicQuery()->whereKey($comboId)->firstOrFail();return $this->publicPromotionRows($request,$combo->branch,$combo->items->pluck('branch_service_offering_id')->all(),$combo->id);
    }
    private function publicPromotionRows(Request $request,Branch $branch,array $ids,?int $comboId=null):array
    {
        $request->validate(['page'=>'sometimes|integer|min:1','limit'=>'sometimes|integer|between:1,50','search'=>'sometimes|string|max:120']);
        $query=Promotion::where('status','ACTIVE')->where('start_date','<=',now())->where('end_date','>',now())->where(fn($q)=>$q->where('business_id',$branch->business_id)->orWhere(fn($p)=>$p->whereNull('business_id')->where('created_by_platform',true)));
        if(!$request->boolean('includeCodes'))$query->where('auto_apply',true);
        else $query->where(fn($q)=>$q->where('auto_apply',true)->orWhere(fn($p)=>$p->whereNotNull('code')->where('code','<>','')));
        $rows=$query->orderBy('id')->get()->filter(fn($rule)=>app(\App\Services\Promotions\PromotionPricingService::class)->scopeEligible($rule,$branch,$ids,$comboId))->map(fn($rule)=>$this->promotionDto($rule)+['type'=>$rule->discount_type==='PERCENTAGE'?'PERCENT':'FIXED','value'=>$rule->discount_value,'endsAt'=>$rule->end_date?->toIso8601String()])->values();
        if($request->filled('search'))$rows=$rows->filter(fn($r)=>str_contains(mb_strtolower($r['name'].' '.($r['code']??'')),mb_strtolower($request->input('search'))))->values();
        if(!$request->has('page'))return $rows->all();$page=(int)$request->input('page',1);$limit=(int)$request->input('limit',6);return ['items'=>$rows->slice(($page-1)*$limit,$limit)->values()->all(),'total'=>$rows->count(),'page'=>$page,'limit'=>$limit];
    }
    public function combos(Request $request): array
    {
        $assignment=$this->scope->current($request);abort_unless(in_array($assignment->role_name,['BUSINESS_OWNER','RECEPTIONIST','STAFF','PLATFORM_ADMIN']),403);
        $branchId=$request->input('branchId') ?? $assignment->branch_id;if($branchId)$this->scope->branch($request,$branchId);
        return Combo::when($assignment->business_id,fn($q)=>$q->where('business_id',$assignment->business_id))->when($branchId,fn($q)=>$q->where('branch_id',$branchId))->latest()->get()->map(fn($row)=>$this->presenter->combo($row))->all();
    }
    public function publicCombos(Request $request,ComboService $service): array
    {
        $branch=$request->input('branchId') ? Branch::publicReady()->findOrFail($request->input('branchId')):null;
        return $service->publicQuery($branch)->get()->map(fn($row)=>$this->presenter->combo($row))->all();
    }
    public function publicCombo(string $id,ComboService $service):array
    {
        return $this->presenter->combo($service->publicQuery()->whereKey($id)->firstOrFail());
    }
    public function comboDetail(Request $request,string $id): array
    {
        $combo=Combo::findOrFail($id);$this->scope->branch($request,$combo->branch_id);return $this->presenter->combo($combo);
    }
    public function saveCombo(Request $request,ComboService $service,?string $id=null): array
    {
        $combo=$id ? Combo::findOrFail($id):null;$r=$id?'sometimes':'required';
        $data=$request->validate(['branchId'=>[$r,'integer','exists:branches,id'],'name'=>[$r,'string','max:255'],'description'=>'nullable|string|max:4000','comboPrice'=>[$r,'numeric','gt:0'],'staffAssignmentMode'=>'sometimes|in:SINGLE_PROVIDER,PER_SERVICE','status'=>'sometimes|in:ACTIVE,INACTIVE','validFrom'=>'nullable|date','validTo'=>'nullable|date','maxUsage'=>'nullable|integer|min:1','services'=>[$r,'array','min:2'],'services.*.serviceId'=>'required|integer|distinct|exists:branch_service_offerings,id','services.*.quantity'=>'sometimes|integer|min:1|max:10','services.*.sortOrder'=>'sometimes|integer|min:0','services.*.transitionMinutes'=>'sometimes|integer|min:0|max:240']);
        $branch=$this->scope->branch($request,$data['branchId'] ?? $combo->branch_id);$this->scope->owner($request,$branch->business_id);
        abort_if($combo&&$combo->branch_id!==$branch->id,422,'Không thể chuyển combo sang chi nhánh khác.');
        $snake=$combo?->getAttributes() ?? ['pricing_mode'=>'FIXED','staff_assignment_mode'=>'SINGLE_PROVIDER','status'=>'ACTIVE'];
        foreach(collect($data)->except('services','branchId')->all() as $key=>$value)$snake[Str::snake($key)]=$value;
        $snake['items']=isset($data['services'])?array_map(fn($item,$index)=>['branch_service_offering_id'=>$item['serviceId'],'quantity'=>$item['quantity'] ?? 1,'sort_order'=>$item['sortOrder'] ?? $index,'transition_minutes'=>$item['transitionMinutes'] ?? 0],$data['services'],array_keys($data['services'])):$combo->items->map(fn($item)=>$item->getAttributes())->all();
        unset($snake['id'],$snake['business_id'],$snake['branch_id'],$snake['created_at'],$snake['updated_at'],$snake['deleted_at']);
        return $this->presenter->combo($service->save($branch,$snake,$combo));
    }
    public function removeCombo(Request $request,string $id)
    {
        $combo=Combo::findOrFail($id);$this->scope->owner($request,$combo->business_id);$combo->update(['status'=>'INACTIVE']);$combo->delete();return response()->noContent();
    }
}
