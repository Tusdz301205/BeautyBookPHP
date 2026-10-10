<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\{Branch, BusinessService, BranchServiceOffering, CanonicalService, ServiceCategory, ServiceVariant, ServicePriceRule};
use App\Services\Beautybook\{ApiScope, ManagementPresenter};
use App\Services\Catalog\CatalogManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ManagementCatalogController extends Controller
{
    public function __construct(private ApiScope $scope, private ManagementPresenter $presenter, private CatalogManagementService $catalog) {}

    public function workspace(Request $request): array
    {
        $assignment = $this->scope->current($request);
        abort_unless(in_array($assignment->role_name,['BUSINESS_OWNER','RECEPTIONIST','STAFF','PLATFORM_ADMIN']),403);
        $branchId = $request->input('branchId');
        $branch = $branchId ? $this->scope->branch($request,$branchId) : null;
        $assignment = $this->scope->forRoles($request,['BUSINESS_OWNER','RECEPTIONIST','STAFF','PLATFORM_ADMIN'],$branch?->business_id,$branch?->id);
        if (!$branchId && in_array($assignment->role_name,['RECEPTIONIST','STAFF'])) $branchId = $assignment->branch_id;
        $businessId = $branch?->business_id ?? ($assignment->role_name === 'PLATFORM_ADMIN' ? $request->input('businessId') : $assignment->business_id);
        return BusinessService::with(['offerings','canonicalService'])->when($businessId, fn ($q) => $q->where('business_id',$businessId))->orderBy('name')->get()->map(fn ($service) => $this->presenter->catalog($service,$branchId ? (int)$branchId : null))->all();
    }

    public function manage(Request $request): array
    {
        $assignment = $this->scope->current($request);
        abort_unless(in_array($assignment->role_name,['BUSINESS_OWNER','RECEPTIONIST','STAFF','PLATFORM_ADMIN']),403);
        $branchId = $request->input('branchId');
        $branch = $branchId ? $this->scope->branch($request,$branchId) : null;
        $assignment = $this->scope->forRoles($request,['BUSINESS_OWNER','RECEPTIONIST','STAFF','PLATFORM_ADMIN'],$branch?->business_id,$branch?->id);
        if (!$branchId && in_array($assignment->role_name,['RECEPTIONIST','STAFF'])) $branchId = $assignment->branch_id;
        $businessId = $branch?->business_id ?? ($assignment->role_name === 'PLATFORM_ADMIN' ? $request->input('businessId') : $assignment->business_id);
        return BranchServiceOffering::with('service')->when($businessId,fn ($q) => $q->where('business_id',$businessId))->when($branchId,fn ($q) => $q->where('branch_id',$branchId))->get()->map(fn ($row) => $this->presenter->offering($row))->all();
    }

    public function manageOffering(Request $request, string $id): array
    {
        $offering = BranchServiceOffering::with('service')->findOrFail($id);
        $this->scope->owner($request, $offering->business_id);
        return $this->presenter->offering($offering);
    }

    public function save(Request $request, ?string $id = null): array
    {
        $service = $id ? BusinessService::findOrFail($id) : null;
        $business = $this->scope->owner($request,$service?->business_id ?? $request->input('businessId'));
        $input = $request->all();
        $categoryId = $input['categoryId'] ?? $service?->service_category_id;
        if(isset($input['categoryId']))abort_unless(collect($this->businessCategories($business->id))->contains('id',(string)$input['categoryId']),422,'Danh mục không thuộc doanh nghiệp');
        if (!$categoryId && !empty($input['canonicalServiceId'])) $categoryId = ServiceCategory::where('slug',CanonicalService::findOrFail($input['canonicalServiceId'])->slug)->value('id');
        if (!$categoryId) $categoryId = ServiceCategory::firstOrCreate(['slug'=>'other'],['name'=>'Dịch vụ khác'])->id;
        $data = ['name'=>$input['name'] ?? $service?->name,'slug'=>$input['slug'] ?? $service?->slug ?? Str::slug($input['name'] ?? '').'-'.Str::lower(Str::random(6)),
            'service_category_id'=>$categoryId,'canonical_service_id'=>$input['canonicalServiceId'] ?? $service?->canonical_service_id,'description'=>$input['description'] ?? $service?->description,
            'keywords'=>$input['keywords'] ?? $service?->keywords,'base_price'=>$input['price'] ?? $service?->base_price,'base_duration_minutes'=>$input['durationMinutes'] ?? $service?->base_duration_minutes,
            'status'=>$input['status'] ?? $service?->status ?? 'ACTIVE','branch_ids'=>$input['branchIds'] ?? (isset($input['branchId']) ? [$input['branchId']] : [])];
        $data = Validator::make($data,['name'=>'required|string|max:160','slug'=>['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',Rule::unique('business_services','slug')->where('business_id',$business->id)->ignore($service?->id)],
            'service_category_id'=>'required|exists:service_categories,id','canonical_service_id'=>'nullable|exists:canonical_services,id','description'=>'nullable|string|max:4000','keywords'=>'nullable|array','keywords.*'=>'string|max:80',
            'base_price'=>'required|numeric|min:0|max:9999999999.99','base_duration_minutes'=>'required|integer|min:5|max:720','status'=>'required|in:ACTIVE,INACTIVE','branch_ids'=>[$service ? 'sometimes':'required','array',$service ? 'min:0':'min:1'],'branch_ids.*'=>'integer|distinct|exists:branches,id'])->validate();
        $saved = $service ? $this->catalog->update($service,$data) : $this->catalog->create($business,$data);
        return $this->presenter->catalog($saved->load(['offerings','canonicalService']));
    }

    public function updateLegacy(Request $request,string $id): array
    {
        return $this->offering($request,$id);
    }

    public function archive(Request $request,string $id)
    {
        $service = BusinessService::findOrFail($id); $this->scope->owner($request,$service->business_id); $this->catalog->archive($service);
        return response()->noContent();
    }

    public function removeLegacy(Request $request,string $id)
    {
        $offering = BranchServiceOffering::findOrFail($id); $this->scope->owner($request,$offering->business_id);
        $offering->forceFill(['is_active'=>false,'bookable'=>false])->save(); $offering->delete(); return response()->noContent();
    }

    public function availability(Request $request,string $id,string $branchId,string $action): array
    {
        $service = BusinessService::findOrFail($id); $this->scope->owner($request,$service->business_id); $branch = $this->scope->branch($request,$branchId);
        abort_unless(in_array($action,['apply','pause','reactivate']),404);
        return DB::transaction(function () use ($service,$branch,$action) {
            $service = BusinessService::whereKey($service->id)->lockForUpdate()->firstOrFail();
            abort_unless($service->business_id === $branch->business_id,403);
            abort_if($action !== 'pause' && $service->status !== 'ACTIVE',422,'Dịch vụ cấp doanh nghiệp đang tạm ngưng.');
            $offering = BranchServiceOffering::where('business_service_id',$service->id)->where('branch_id',$branch->id)->lockForUpdate()->first();
            if (!$offering) { abort_unless($action === 'apply',404); $offering = BranchServiceOffering::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'business_service_id'=>$service->id,'price'=>$service->base_price,'duration_minutes'=>$service->base_duration_minutes,'bookable'=>true,'is_active'=>true]); }
            else $offering->forceFill(['bookable'=>$action !== 'pause','is_active'=>$action !== 'pause'])->save();
            return $this->presenter->offering($offering->load('service'));
        });
    }

    public function offering(Request $request,string $id): array
    {
        $offering = BranchServiceOffering::findOrFail($id); $this->scope->owner($request,$offering->business_id);
        $data = $request->validate(['price'=>'sometimes|numeric|min:0|max:9999999999.99','durationMinutes'=>'sometimes|integer|min:5|max:720','status'=>'sometimes|in:ACTIVE,PAUSED,INACTIVE','isBookable'=>'sometimes|boolean','bookable'=>'sometimes|boolean']);
        $result = $this->catalog->updateOffering($offering,['price'=>$data['price'] ?? $offering->price,'duration_minutes'=>$data['durationMinutes'] ?? $offering->duration_minutes,'bookable'=>$data['bookable'] ?? $data['isBookable'] ?? (isset($data['status']) ? $data['status']==='ACTIVE' : $offering->bookable)]);
        return $this->presenter->offering($result);
    }

    public function variant(Request $request,?string $id = null,?string $variantId = null): array
    {
        $variant = $variantId ? ServiceVariant::findOrFail($variantId) : null;
        $offering = $variant?->offering ?? BranchServiceOffering::findOrFail($id); $this->scope->owner($request,$offering->business_id);
        $data = $variant?->toArray() ?? ['price_type'=>'FIXED','buffer_before_minutes'=>0,'buffer_after_minutes'=>0,'consultation_required'=>false,'status'=>'ACTIVE'];
        foreach (['code','name','description','priceType','price','maxPrice','durationMinutes','maxDurationMinutes','bufferBeforeMinutes','bufferAfterMinutes','consultationRequired','eligibilityRules','status'] as $key) if ($request->exists($key)) $data[Str::snake($key)]=$request->input($key);
        $data = Validator::make($data,['code'=>['required','regex:/^[A-Za-z0-9_-]+$/','max:80',Rule::unique('service_variants','code')->where('branch_service_offering_id',$offering->id)->ignore($variant?->id)],'name'=>'required|string|max:160','description'=>'nullable|string|max:4000','price_type'=>'required|in:FIXED,FROM,RANGE,QUOTE','price'=>'nullable|numeric|min:0','max_price'=>'nullable|numeric|min:0|gte:price','duration_minutes'=>'nullable|integer|min:5|max:720','max_duration_minutes'=>'nullable|integer|min:5|max:720','buffer_before_minutes'=>'required|integer|min:0|max:240','buffer_after_minutes'=>'required|integer|min:0|max:240','consultation_required'=>'required|boolean','eligibility_rules'=>'nullable|array','status'=>'required|in:ACTIVE,INACTIVE'])->validate();
        abort_if($data['price_type'] !== 'QUOTE' && !isset($data['price']),422,'Biến thể cần giá.');
        abort_if($data['price_type'] === 'RANGE' && !isset($data['max_price']),422,'Biến thể khoảng giá cần giá tối đa.');
        if ($variant) { $variant->fill($data); $variant->version++; $variant->save(); } else $variant=$this->catalog->createVariant($offering,$data);
        return $this->presenter->camel($variant->toArray());
    }

    public function updateVariant(Request $request,string $variantId): array
    {
        return $this->variant($request,null,$variantId);
    }

    public function dependency(Request $request,string $id): array
    {
        $offering=BranchServiceOffering::findOrFail($id); $this->scope->owner($request,$offering->business_id);
        $data=$request->validate(['requiredServiceId'=>'required|integer|exists:branch_service_offerings,id','dependencyType'=>'required|in:ADD_ON,REQUIRED,INCOMPATIBLE,PREREQUISITE,REQUIRES,EXCLUDES']);
        $data['dependencyType']=match($data['dependencyType']){'PREREQUISITE','REQUIRES'=>'REQUIRED','EXCLUDES'=>'INCOMPATIBLE',default=>$data['dependencyType']};
        $existing=$offering->dependencies->map(fn ($d)=>['required_offering_id'=>$d->required_offering_id,'dependency_type'=>$d->dependency_type])->all();
        $existing[]= ['required_offering_id'=>$data['requiredServiceId'],'dependency_type'=>$data['dependencyType']];
        $this->catalog->replaceDependencies($offering,$existing); return $this->presenter->camel($offering->dependencies()->get()->toArray());
    }

    public function priceRule(Request $request,string $id): array
    {
        $offering=BranchServiceOffering::findOrFail($id); $this->scope->owner($request,$offering->business_id);
        $data=$request->validate(['name'=>'required|string|max:160','priority'=>'sometimes|integer|min:0|max:100000','adjustmentType'=>'required|in:FIXED_AMOUNT,PERCENTAGE','adjustmentValue'=>'required|numeric|min:-999999999|max:999999999','conditions'=>'sometimes|array','active'=>'sometimes|boolean','validFrom'=>'nullable|date','validTo'=>'nullable|date|after:validFrom','variantId'=>'nullable|integer|exists:service_variants,id']);
        $variant=empty($data['variantId']) ? null : ServiceVariant::findOrFail($data['variantId']);
        $snake=[]; foreach ($data as $key=>$value) $snake[Str::snake($key)]=$value;
        return $this->presenter->camel($this->catalog->createPriceRule($offering,$variant,$snake)->toArray());
    }

    public function categories(Request $request): array {return $this->businessCategories($this->scope->owner($request,$request->input('businessId'))->id);}
    private function businessCategories(int $businessId): array
    {
        $links=DB::table('beautybook_business_categories')->where('business_id',$businessId)->get()->keyBy('service_category_id');
        $ids=BusinessService::where('business_id',$businessId)->pluck('service_category_id')->merge($links->keys())->unique();
        return ServiceCategory::whereIn('id',$ids)->orderBy('name')->get()->map(fn($category)=>['id'=>(string)$category->id,'businessId'=>(string)$businessId,'parentId'=>isset($links[$category->id]) && $links[$category->id]->parent_category_id ? (string)$links[$category->id]->parent_category_id:null,'name'=>$category->name,'slug'=>$links[$category->id]->source_slug ?? $category->slug])->all();
    }
    public function publicCanonicals(): array { return $this->canonicalRows(true); }
    public function canonicals(Request $request): array { $this->scope->platform($request); return $this->canonicalRows(false); }
    private function canonicalRows(bool $publicOnly): array
    {
        return CanonicalService::when($publicOnly,fn($query)=>$query->where('status','ACTIVE'))->withCount('businessServices')->orderBy('parent_id')->orderBy('name')->get()->map(function($row){
            $data=$this->presenter->camel($row->toArray());unset($data['businessServicesCount']);
            return $data+['_count'=>['businessServices'=>$row->business_services_count]];
        })->all();
    }
    public function category(Request $request): array
    {
        $data=$request->validate(['businessId'=>'required|integer|exists:businesses,id','name'=>'required|string|max:160','slug'=>'required|string|max:160|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','parentId'=>'nullable|integer|exists:service_categories,id']);
        $business=$this->scope->owner($request,$data['businessId']);
        return DB::transaction(function()use($business,$data){
            \App\Models\Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $categories=$this->businessCategories($business->id);
            abort_if(collect($categories)->contains('slug',$data['slug']),422,'Slug danh mục đã tồn tại trong doanh nghiệp');
            if(!empty($data['parentId']))abort_unless(collect($categories)->contains('id',(string)$data['parentId']),422,'Danh mục cha không thuộc doanh nghiệp');
            $category=ServiceCategory::create(['name'=>$data['name'],'slug'=>'tenant-'.$business->id.'-'.$data['slug']]);
            DB::table('beautybook_business_categories')->insert(['business_id'=>$business->id,'service_category_id'=>$category->id,'parent_category_id'=>$data['parentId'] ?? null,'source_slug'=>$data['slug'],'created_at'=>now(),'updated_at'=>now()]);
            return ['id'=>(string)$category->id,'businessId'=>(string)$business->id,'parentId'=>isset($data['parentId'])?(string)$data['parentId']:null,'name'=>$category->name,'slug'=>$data['slug']];
        },3);
    }
    public function canonical(Request $request,?string $id=null): array
    {
        $this->scope->platform($request); $canonical=$id ? CanonicalService::findOrFail($id):new CanonicalService;
        $data=$request->validate(['name'=>[$id?'sometimes':'required','string','max:160'],'code'=>[$id?'sometimes':'required','string',Rule::unique('canonical_services','code')->ignore($id)],'slug'=>[$id?'sometimes':'required','string',Rule::unique('canonical_services','slug')->ignore($id)],'description'=>'nullable|string|max:4000','status'=>'sometimes|in:ACTIVE,DEPRECATED,MERGED','synonyms'=>'sometimes|array','synonyms.*'=>'string|max:160','parentId'=>'nullable|integer|exists:canonical_services,id','replacementCanonicalId'=>'nullable|integer|exists:canonical_services,id']);
        if(($data['status'] ?? null)==='MERGED')abort_if(empty($data['replacementCanonicalId']) || (string)$data['replacementCanonicalId']===(string)$id,422,'Canonical MERGED phải có canonical thay thế khác chính nó');
        if(isset($data['status']) && $data['status']!=='MERGED') {abort_if(!empty($data['replacementCanonicalId']),422,'Chỉ canonical MERGED mới có canonical thay thế');$data['replacement_canonical_id']=null;}
        if(isset($data['synonyms']))$data['synonyms']=array_values(array_unique(array_filter(array_map('trim',$data['synonyms']))));
        if(array_key_exists('parentId',$data)){
            abort_if($data['parentId']!==null && (string)$data['parentId']===(string)$id,422);
            if($data['parentId']!==null)abort_unless(CanonicalService::whereKey($data['parentId'])->where('status','ACTIVE')->exists(),422,'Canonical cha phải đang hoạt động');
            $data['parent_id']=$data['parentId'];unset($data['parentId']);
        }
        if(array_key_exists('replacementCanonicalId',$data)){
            if($data['replacementCanonicalId']!==null)abort_unless(CanonicalService::whereKey($data['replacementCanonicalId'])->where('status','ACTIVE')->exists(),422,'Canonical thay thế phải đang hoạt động');
            $data['replacement_canonical_id']=$data['replacementCanonicalId'];unset($data['replacementCanonicalId']);
        }
        $canonical->fill($data)->save(); return $this->presenter->camel($canonical->toArray());
    }
}
