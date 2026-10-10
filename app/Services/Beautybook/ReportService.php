<?php

namespace App\Services\Beautybook;

use App\Models\{Booking,Branch,BranchServiceOffering,ServiceCategory,StaffProfile,User};
use App\Services\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/** Reports share the persisted cash ledger and branch-local calendar boundaries. */
class ReportService
{
    public function __construct(private ApiScope $scope) {}

    public function branches(Request $r): Collection
    {
        $a=$this->scope->forRoles($r,['PLATFORM_ADMIN','BUSINESS_OWNER']);
        $query=Branch::query()->when($a->role_name==='BUSINESS_OWNER',fn($q)=>$q->where('business_id',$a->business_id));
        if($r->branchId && $r->branchId !== 'ALL') { $branch=$this->scope->branch($r,$r->branchId); $query->whereKey($branch->id); }
        return $query->get();
    }

    public function bookings(Collection $branches): Collection
    {
        return Booking::whereIn('branch_id',$branches->modelKeys())->with(['branch','services','pricingSnapshot'])->get();
    }

    public function ledger(Collection $branches): Collection
    {
        return DB::table('payment_ledger_entries')->join('bookings','bookings.id','=','payment_ledger_entries.booking_id')->whereIn('bookings.branch_id',$branches->modelKeys())->whereNull('bookings.deleted_at')->select('payment_ledger_entries.*','bookings.branch_id')->get();
    }

    private function localDate(mixed $instant,int|string $branchId,Collection $branches): string
    {
        // Eloquent appointment casts carry UTC instants. Raw ledger/audit and
        // redemption timestamps are written by now() in the application timezone.
        return CarbonImmutable::parse($instant,config('app.timezone'))->setTimezone($branches->firstWhere('id',(int)$branchId)?->timezone ?: 'Asia/Ho_Chi_Minh')->toDateString();
    }

    private function between(Collection $rows,string $column,string $from,string $to,Collection $branches): Collection
    {
        return $rows->filter(fn($row)=>($date=$this->localDate($row->$column,$row->branch_id,$branches)) >= $from && $date <= $to)->values();
    }

    public function cash(Collection $ledger): array
    {
        $gross=(int)$ledger->where('type','PAYMENT_RECEIVED')->sum('amount_cents');
        $refund=(int)$ledger->whereIn('type',['REFUND','REVERSAL'])->sum('amount_cents');
        return ['grossRevenue'=>$gross/100,'refundAmount'=>$refund/100,'netRevenue'=>($gross-$refund)/100];
    }

    private function appliedDiscounts(Collection $branches): Collection
    {
        $rows=collect();
        foreach(['promotion_redemptions','voucher_redemptions'] as$table) {
            $rows=$rows->concat(DB::table($table)->whereIn('branch_id',$branches->modelKeys())->where('status','APPLIED')->whereNotNull('applied_at')->get()->map(fn($row)=>(object)['id'=>$table.':'.$row->id,'branch_id'=>$row->branch_id,'applied_at'=>$row->applied_at,'amount_cents'=>max(0,Money::cents($row->amount))]));
        }
        $loyalty=DB::table('loyalty_transactions as redemption')->join('bookings','bookings.id','=','redemption.booking_id')->whereIn('bookings.branch_id',$branches->modelKeys())->where('redemption.type','REDEEM')->whereNotExists(fn($q)=>$q->selectRaw('1')->from('loyalty_transactions as reversal')->whereColumn('reversal.reversal_of_id','redemption.id'))->select('redemption.*','bookings.branch_id')->get();
        $rows=$rows->concat($loyalty->map(function($row) {
            $rule=json_decode($row->rule_snapshot ?: '{}',true,512,JSON_THROW_ON_ERROR);
            return (object)['id'=>'loyalty_transactions:'.$row->id,'branch_id'=>$row->branch_id,'applied_at'=>$row->created_at,'amount_cents'=>abs((int)$row->points)*Money::cents((string)($rule['redemption_value_per_point'] ?? '0'))];
        }));
        $manual=DB::table('beautybook_booking_edits as edit')->join('bookings','bookings.id','=','edit.booking_id')->whereIn('bookings.branch_id',$branches->modelKeys())->whereIn('edit.action',['REPRICE','ADD','REMOVE','SKIP'])->select('edit.*','bookings.branch_id')->get();
        return $rows->concat($manual->map(function($row) {
            $before=json_decode($row->before,true,512,JSON_THROW_ON_ERROR);
            $after=json_decode($row->after,true,512,JSON_THROW_ON_ERROR);
            $old=Money::cents((string)($before['price_at_booking'] ?? '0'));
            $new=Money::cents((string)($after['price_at_booking'] ?? '0'));
            $delta=match($row->action) {'ADD'=>$new,'REMOVE'=>-$old,'SKIP'=>($before['status'] ?? null)==='SCHEDULED' ? -$old : 0,default=>$new-$old};
            // Manual price edits belong in drill-down, but the source discount
            // metric counts promotion/voucher/loyalty/package adjustments only.
            return (object)['id'=>'edit:'.$row->id,'branch_id'=>$row->branch_id,'applied_at'=>$row->created_at,'type'=>'MANUAL','signed_delta_cents'=>$delta,'amount_cents'=>0];
        }));
    }

    public function overview(Request $r): array
    {
        $branches=$this->branches($r); $bookings=$this->bookings($branches); $a=$this->scope->current($r);
        return $this->cash($this->ledger($branches))+['totalRevenue'=>$this->cash($this->ledger($branches))['netRevenue'],'totalUsers'=>$a->role_name==='PLATFORM_ADMIN' ? User::where('is_active',true)->count() : $bookings->pluck('customer_profile_id')->filter()->unique()->count(),'totalBranches'=>$branches->filter(fn($b)=>($b->status->value ?? $b->status)==='ACTIVE')->count(),'totalBookings'=>$bookings->count(),'pendingBookings'=>$bookings->where('status','PENDING')->count()];
    }

    public function revenue(Request $r): array
    {
        $r->validate(['year'=>'nullable|integer|between:2000,2200']); $year=(int)($r->year ?: now()->year);
        $branches=$this->branches($r); $ledger=$this->ledger($branches); $rows=[];
        for($month=1;$month<=12;$month++) { $from=CarbonImmutable::create($year,$month,1)->toDateString(); $to=CarbonImmutable::parse($from)->endOfMonth()->toDateString(); $cash=$this->cash($this->between($ledger,'created_at',$from,$to,$branches)); $rows[]=['month'=>'T'.$month]+$cash+['revenue'=>round($cash['netRevenue']/1000000,1)]; }
        return $rows;
    }

    public function categories(Request $r): array
    {
        $branches=$this->branches($r); $bookings=$this->bookings($branches); $result=[];
        $categories=ServiceCategory::all();
        foreach($categories as$category) { $ids=BranchServiceOffering::whereIn('branch_id',$branches->modelKeys())->whereHas('service',fn($q)=>$q->where('service_category_id',$category->id))->pluck('id'); $rows=$bookings->flatMap(fn($b)=>$b->services->filter(fn($item)=>$ids->contains($item->branch_service_offering_id))->map(fn($item)=>['status'=>$b->status])); $result[]=$this->counts($category->name,$rows); }
        return collect($result)->sortByDesc('value')->values()->all();
    }

    private function counts(string $name,Collection $rows): array
    {
        return ['name'=>$name,'value'=>$rows->count(),'bookedCount'=>$rows->count(),'completedCount'=>$rows->where('status','COMPLETED')->count(),'cancelledCount'=>$rows->whereIn('status',['CANCELLED','REJECTED','EXPIRED'])->count(),'noShowCount'=>$rows->where('status','NO_SHOW')->count()];
    }

    public function services(Request $r): array
    {
        $branches=$this->branches($r); $bookings=$this->bookings($branches);
        return BranchServiceOffering::whereIn('branch_id',$branches->modelKeys())->with('service')->get()->map(fn($offering)=>$this->counts($offering->service->name,$bookings->flatMap(fn($b)=>$b->services->where('branch_service_offering_id',$offering->id)->map(fn($item)=>['status'=>$b->status]))))->sortByDesc('value')->take(5)->values()->all();
    }

    public function topBranches(Request $r): array
    {
        $this->scope->platform($r); $r->validate(['limit'=>'nullable|integer|between:1,100']); $branches=$this->branches($r); $bookings=$this->bookings($branches);
        return $branches->map(fn($b)=>['id'=>(string)$b->id,'name'=>$b->business?->name ?: $b->name,'bookings'=>$bookings->where('branch_id',$b->id)->count()])->sortByDesc('bookings')->take((int)($r->limit ?: 5))->values()->all();
    }

    public function userGrowth(Request $r): array
    {
        $this->scope->platform($r); $r->validate(['year'=>'nullable|integer|between:2000,2200']); $year=(int)($r->year ?: now()->year); $rows=[];
        for($month=1;$month<=12;$month++) $rows[]=['month'=>'T'.$month,'users'=>User::where('created_at','<',CarbonImmutable::create($year,$month,1)->addMonth())->count()];
        return $rows;
    }

    public function staff(Request $r): array
    {
        $branches=$this->branches($r); $bookings=$this->bookings($branches); $ledger=$this->ledger($branches);
        return StaffProfile::whereIn('branch_id',$branches->modelKeys())->get()->map(function($staff)use($bookings,$ledger) {
            $count=0;$revenue=0;
            foreach($bookings as$b) { $net=max(0,(int)round($this->cash($ledger->where('booking_id',$b->id))['netRevenue']*100)); $subtotal=$b->services->sum(fn($s)=>Money::cents($s->price_at_booking)); foreach($b->services->where('staff_profile_id',$staff->id) as$item) { $count++; if($b->status==='COMPLETED' && $subtotal>0) $revenue+=Money::multiplyDivideRound($net,Money::cents($item->price_at_booking),$subtotal); } }
            return ['name'=>$staff->user?->name ?: $staff->full_name,'bookings'=>$count,'revenue'=>$revenue/100];
        })->sortByDesc('revenue')->take(10)->values()->all();
    }

    public function range(Request $r): array
    {
        $data=$r->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to']))>366,422,'Khoảng báo cáo tối đa 367 ngày.');
        return [$data['from'],$data['to']];
    }

    public function financial(Request $r): array
    {
        [$from,$to]=$this->range($r); $branches=$this->branches($r); $all=$this->bookings($branches); $bookings=$this->between($all,'appointment_start_at',$from,$to,$branches); $ledger=$this->ledger($branches); $cash=$this->cash($this->between($ledger,'created_at',$from,$to,$branches)); $recognized=0; $outstanding=0;
        foreach($bookings as$b) { $net=(int)round($this->cash($ledger->where('booking_id',$b->id))['netRevenue']*100); $price=Money::cents($b->pricingSnapshot?->final_amount ?? $b->total_amount); if($b->status==='COMPLETED') $recognized+=max(0,min($price,$net)); if(!in_array($b->status,['CANCELLED','REJECTED','EXPIRED'],true)) $outstanding+=max(0,$price-$net); }
        $periodLedger=$this->between($ledger,'created_at',$from,$to,$branches);
        $adjustments=$this->between($this->appliedDiscounts($branches),'applied_at',$from,$to,$branches);
        return ['definitions'=>['grossCollected'=>'Giao dịch thu tiền đã xác minh.','netCollected'=>'Tiền thu trừ hoàn tiền và đảo giao dịch thực tế trong kỳ.','recognizedServiceRevenue'=>'Tiền thu ròng trên lịch hoàn thành, không vượt giá cuối cùng.','outstandingAmount'=>'Giá cuối cùng chưa thu trên lịch còn hiệu lực.'],'from'=>$from,'to'=>$to,'branchCount'=>$branches->count(),'bookedCount'=>$bookings->count(),'completedCount'=>$bookings->where('status','COMPLETED')->count(),'cancelledCount'=>$bookings->whereIn('status',['CANCELLED','REJECTED','EXPIRED'])->count(),'noShowCount'=>$bookings->where('status','NO_SHOW')->count(),'grossCollected'=>$cash['grossRevenue'],'refundAmount'=>$cash['refundAmount'],'netCollected'=>$cash['netRevenue'],'recognizedServiceRevenue'=>$recognized/100,'discountAmount'=>$adjustments->sum('amount_cents')/100,'outstandingAmount'=>$outstanding/100,'cancellationFeeCollected'=>0,'noShowFeeCollected'=>0,'drillDown'=>['bookingIds'=>$bookings->pluck('id')->map('strval')->values(),'paymentTransactionIds'=>$periodLedger->where('type','PAYMENT_RECEIVED')->pluck('payment_transaction_id')->map('strval')->values(),'refundIds'=>$periodLedger->where('type','REFUND')->pluck('refund_request_id')->filter()->map('strval')->values(),'adjustmentIds'=>$adjustments->pluck('id')->values()]];
    }

    public function ownerDashboard(Request $r): array
    {
        $this->scope->owner($r); [$from,$to]=$this->range($r); $branches=$this->branches($r); $all=$this->bookings($branches); $ledger=$this->ledger($branches); $days=(int)CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to))+1; $previousFrom=CarbonImmutable::parse($from)->subDays($days)->toDateString(); $previousTo=CarbonImmutable::parse($from)->subDay()->toDateString();
        $bookings=$this->between($all,'appointment_start_at',$from,$to,$branches); $previous=$this->between($all,'appointment_start_at',$previousFrom,$previousTo,$branches); $cash=$this->cash($this->between($ledger,'created_at',$from,$to,$branches)); $previousCash=$this->cash($this->between($ledger,'created_at',$previousFrom,$previousTo,$branches));
        $compare=fn($current,$prior)=>['current'=>$current,'previous'=>$prior,'changePercent'=>$prior ? round(($current-$prior)/abs($prior)*100,1) : ($current===0 ? 0 : null)];
        $series=[]; $reviewTrend=[]; $reviews=$this->between(DB::table('reviews')->join('bookings','bookings.id','=','reviews.booking_id')->whereIn('reviews.booking_id',$all->modelKeys())->select('reviews.*','bookings.branch_id')->get(),'created_at',$from,$to,$branches);
        for($i=0;$i<$days;$i++) { $date=CarbonImmutable::parse($from)->addDays($i)->toDateString(); $previousDate=CarbonImmutable::parse($previousFrom)->addDays($i)->toDateString(); $daily=$this->cash($this->between($ledger,'created_at',$date,$date,$branches)); $series[]=['date'=>$date,'previousDate'=>$previousDate]+$daily+['previousNetRevenue'=>$this->cash($this->between($ledger,'created_at',$previousDate,$previousDate,$branches))['netRevenue'],'discountAmount'=>$this->between($bookings,'appointment_start_at',$date,$date,$branches)->sum(fn($b)=>Money::cents($b->pricingSnapshot?->discount_amount ?? '0'))/100]; $dailyReviews=$reviews->where('status','APPROVED')->filter(fn($v)=>$this->localDate($v->created_at,$v->branch_id,$branches)===$date); $reviewTrend[]=['date'=>$date,'count'=>$dailyReviews->count(),'average'=>$dailyReviews->count() ? round($dailyReviews->avg('overall_rating'),1) : null]; }
        $serviceMap=[]; $comboMap=[];
        foreach($bookings as$b) { $net=max(0,(int)round($this->cash($ledger->where('booking_id',$b->id))['netRevenue']*100)); $recognized=$b->status==='COMPLETED' ? min(Money::cents($b->pricingSnapshot?->final_amount ?? $b->total_amount),$net) : 0; $subtotal=$b->services->sum(fn($item)=>Money::cents($item->price_at_booking)); foreach($b->services as$item) { $key=$item->branch_service_offering_id; $serviceMap[$key] ??= ['id'=>(string)$key,'name'=>$item->service_name_snapshot,'bookings'=>0,'revenue'=>0]; $serviceMap[$key]['bookings']++; $serviceMap[$key]['revenue']+=($subtotal>0 ? Money::multiplyDivideRound($recognized,Money::cents($item->price_at_booking),$subtotal) : 0)/100; } foreach(DB::table('combo_booking_usages')->where('booking_id',$b->id)->get() as$usage) { $combo=DB::table('combos')->find($usage->combo_id); $comboMap[$usage->combo_id] ??= ['id'=>(string)$usage->combo_id,'name'=>$combo?->name,'bookings'=>0,'revenue'=>0]; $comboMap[$usage->combo_id]['bookings']++; $comboMap[$usage->combo_id]['revenue']+=$recognized/100; } }
        $approved=$reviews->where('status','APPROVED');
        return ['range'=>compact('from','to','previousFrom','previousTo'),'scope'=>['branchId'=>$r->branchId,'branchCount'=>$branches->count()],'kpis'=>['netRevenue'=>$compare($cash['netRevenue'],$previousCash['netRevenue']),'bookings'=>$compare($bookings->count(),$previous->count()),'completedBookings'=>$compare($bookings->where('status','COMPLETED')->count(),$previous->where('status','COMPLETED')->count()),'refundAmount'=>$compare($cash['refundAmount'],$previousCash['refundAmount'])],'charts'=>['revenueSeries'=>$series,'bookingStatus'=>array_map(fn($status)=>['status'=>$status,'count'=>$bookings->where('status',$status)->count()],['PENDING','CONFIRMED','CHECKED_IN','IN_PROGRESS','COMPLETED','CANCELLED','NO_SHOW']),'branchComparison'=>$branches->map(fn($branch)=>['branchId'=>(string)$branch->id,'branchName'=>$branch->name,'netRevenue'=>$this->cash($this->between($ledger->where('branch_id',$branch->id),'created_at',$from,$to,$branches))['netRevenue'],'bookingCount'=>$bookings->where('branch_id',$branch->id)->count(),'completedBookings'=>$bookings->where('branch_id',$branch->id)->where('status','COMPLETED')->count(),'activeStaff'=>$branch->staffProfiles()->where('status','ACTIVE')->count()]),'topServices'=>collect($serviceMap)->sortByDesc('bookings')->take(8)->values(),'topCombos'=>collect($comboMap)->sortByDesc('bookings')->take(5)->values(),'review'=>['average'=>$approved->count() ? round($approved->avg('overall_rating'),1) : null,'count'=>$approved->count(),'pending'=>$reviews->where('status','PENDING')->count(),'distribution'=>array_map(fn($rating)=>['rating'=>$rating,'count'=>$approved->where('overall_rating',$rating)->count()],[1,2,3,4,5]),'trend'=>$reviewTrend]]];
    }
}
