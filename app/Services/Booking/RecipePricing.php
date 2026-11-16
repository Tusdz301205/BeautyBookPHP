<?php

namespace App\Services\Booking;

use App\Models\{Branch,CustomerProfile,Promotion};
use App\Services\Promotions\PromotionPricingService as Money;
use DomainException;

/** GlowMe purchased-group pricing: each standalone unit or complete combo chooses one best offer. */
final class RecipePricing
{
    public function quote(Branch $branch,array $lines,?int $customerId,?string $code,bool $lock):array
    {
        $groups=[];
        foreach($lines as $i=>$line){$key=$line['combo_id']!==null?'combo:'.$line['combo_group_order']:'unit:'.$i;$groups[$key][]=$i;}
        $customer=$customerId?CustomerProfile::findOrFail($customerId):null;
        $engine=app(Money::class);$code=$code?strtoupper(trim($code)):null;
        $query=Promotion::where('status','ACTIVE')->where('discount_value','>',0)->where('start_date','<=',now())->where('end_date','>',now())
            ->where(fn($q)=>$q->where('business_id',$branch->business_id)->orWhere(fn($p)=>$p->whereNull('business_id')->where('created_by_platform',true)))
            ->where(fn($q)=>$q->where('auto_apply',true)->when($code,fn($p)=>$p->orWhere('code',$code)))->orderBy('id');
        if($lock)$query->lockForUpdate();
        $programs=$query->get()->filter(fn($p)=>$customer && $engine->audienceEligible($p->audience,$customer,$branch) && $engine->quotaEligible($p,$customerId,false));
        $validCode=false;$finals=[];$originals=[];$rules=[];$names=[];
        foreach($lines as $i=>$line)$originals[$i]=Money::cents($line['original_unit_price']??$line['unit_price']);
        foreach($groups as $positions){
            $first=$lines[$positions[0]];$comboId=$first['combo_id'];
            $ids=array_map(fn($i)=>$lines[$i]['offering']->id,$positions);
            $base=array_map(fn($i)=>Money::cents($lines[$i]['unit_price']),$positions);
            $original=array_map(fn($i)=>$originals[$i],$positions);
            $best=$base;$winner=null;$winningDiscount=0;$winningPath='BASE';$winningEligible=array_fill(0,count($positions),false);
            foreach($programs as $program){
                if(!$engine->scopeEligible($program,$branch,$ids,$comboId))continue;
                if($code && $program->code===$code)$validCode=true;
                $all=$program->target_mode==='ALL'||(!in_array($program->target_mode,['ALL','SELECTED'],true)&&!$program->service_ids&&!$program->combo_ids);
                $eligible=array_map(fn($id)=>$all||in_array($id,$program->service_ids??[]),$ids);
                $paths=[];
                if(in_array(true,$eligible,true)){
                    $weights=array_map(fn($amount,$yes)=>$yes?$amount:0,$original,$eligible);
                    $discount=$this->discount($program,array_sum($weights));
                    $allocated=BookingRecipe::allocate($discount,$weights);
                    $paths[]=['SERVICE',array_map(fn($amount,$less)=>$amount-$less,$original,$allocated),$discount,$eligible];
                }
                if($comboId && ($all||in_array($comboId,$program->combo_ids??[]))){
                    $discount=$this->discount($program,array_sum($base));$allocated=BookingRecipe::allocate($discount,$base);
                    $paths[]=['COMBO',array_map(fn($amount,$less)=>$amount-$less,$base,$allocated),$discount,array_fill(0,count($positions),true)];
                }
                foreach($paths as [$path,$candidate,$discount,$candidateEligible]){
                    if($discount<=0)continue;$difference=array_sum($candidate)-array_sum($best);
                    if($difference<0 || ($difference===0 && $winner && ((int)$program->id<(int)$winner->id || ($program->id===$winner->id && $path==='COMBO' && $winningPath!=='COMBO')))){
                        $best=$candidate;$winner=$program;$winningDiscount=$discount;$winningPath=$path;$winningEligible=$candidateEligible;
                    }
                }
            }
            foreach($positions as $ordinal=>$position){$finals[$position]=$best[$ordinal];$names[$position]=$winningEligible[$ordinal]?$winner?->name:null;}
            if($winner){
                $key=(string)$winner->id;
                if(!isset($rules[$key]))$rules[$key]=['id'=>$winner->id,'type'=>'PROMOTION','version'=>$winner->version,'name'=>$winner->name,'cents'=>0,'amount'=>'0.00','stacking_allowed'=>false,'snapshot'=>['discount_type'=>$winner->discount_type,'discount_value'=>$winner->discount_value,'service_ids'=>[],'combo_id'=>$comboId,'requested_code'=>$code&&$winner->code===$code?$code:null,'target_mode'=>$winner->target_mode,'code'=>$winner->code,'groups'=>[]]];
                $rules[$key]['cents']+=$winningDiscount;$rules[$key]['amount']=Money::money($rules[$key]['cents']);
                $rules[$key]['snapshot']['service_ids']=array_values(array_unique([...$rules[$key]['snapshot']['service_ids'],...$ids]));
                $rules[$key]['snapshot']['groups'][]=['positions'=>$positions,'combo_id'=>$comboId,'path'=>$winningPath];
            }
        }
        if($code&&!$validCode)throw new DomainException('Mã ưu đãi không hợp lệ cho lựa chọn này.');
        ksort($finals);ksort($names);$originalTotal=array_sum($originals);$total=array_sum($finals);
        if($originalTotal>99999999999999 || $total>$originalTotal)throw new DomainException('Giá đặt lịch vượt giới hạn hỗ trợ.');
        return ['subtotal_amount'=>Money::money($originalTotal),'discount_amount'=>Money::money($originalTotal-$total),'final_amount'=>Money::money($total),'discount_rules'=>array_values($rules),'promotion_code_valid'=>$validCode,'line_finals'=>$finals,'line_originals'=>$originals,'line_promotion_names'=>$names];
    }

    private function discount(Promotion $program,int $base):int
    {
        $value=Money::cents((string)$program->discount_value);
        if($program->discount_type==='PERCENTAGE'){
            if($value>10000)throw new DomainException('Tỷ lệ ưu đãi không hợp lệ.');
            $product=bcmul((string)$base,(string)$value,0);$amount=(int)bcdiv(bcadd($product,'5000',0),'10000',0);
            if($program->max_discount!==null)$amount=min($amount,Money::cents((string)$program->max_discount));
        }else $amount=$value;
        return min($base,max(0,$amount));
    }
}
