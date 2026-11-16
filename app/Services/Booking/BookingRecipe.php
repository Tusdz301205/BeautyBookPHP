<?php

namespace App\Services\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class BookingRecipe
{
    public static function allocate(int $target,array $weights): array
    {
        if(!$weights || $target<0 || min($weights)<0)throw new \DomainException('Giá phân bổ không hợp lệ.');
        $total=array_sum($weights);if($total===0){$weights=array_fill(0,count($weights),1);$total=count($weights);}
        $amounts=[];$fractions=[];
        foreach($weights as $i=>$weight){$product=bcmul((string)$target,(string)$weight,0);$amounts[$i]=(int)bcdiv($product,(string)$total,0);$fractions[$i]=bcmod($product,(string)$total);}
        $remainder=$target-array_sum($amounts);uksort($fractions,fn($a,$b)=>bccomp($fractions[$b],$fractions[$a])?:$a<=>$b);
        foreach(array_keys($fractions) as $i){if($remainder--<=0)break;$amounts[$i]++;}return $amounts;
    }
    public static function input(Request $r, bool $store): array
    {
        $rules=['branchId'=>'required|integer|min:1','selections'=>'required|array|list|min:1|max:100','selections.*.serviceId'=>'nullable|integer|min:1','selections.*.comboId'=>'nullable|integer|min:1','selections.*.variantId'=>'nullable|integer|min:1','selections.*.staffId'=>'nullable|integer|min:1','selections.*.quantity'=>'required|integer|between:1,100','promotionCode'=>['nullable','string','regex:/^[A-Za-z0-9][A-Za-z0-9_-]{2,31}$/D']];
        if($store)$rules+=['startAt'=>'required|date','contact'=>'required|array','contact.name'=>'required|string|min:2|max:120','contact.phone'=>['required','string','max:20','regex:/^\+?[0-9 ()-]{8,20}$/D'],'contact.email'=>'nullable|email|max:254','contactConsent'=>'required|accepted','violationAcknowledged'=>'sometimes|boolean','note'=>'nullable|string|max:1000'];
        $v=$r->validate($rules);$units=0;
        foreach($v['selections'] as $s){abort_unless(isset($s['serviceId'])!==isset($s['comboId']),422,'Chọn dịch vụ hoặc combo cho mỗi lựa chọn.');abort_if(isset($s['comboId'])&&isset($s['variantId']),422,'Combo đã có lựa chọn dịch vụ riêng.');$units+=$s['quantity'];}abort_if($units>100,422,'Tối đa 100 đơn vị dịch vụ.');
        $data=['selections'=>$v['selections'],'source_timeline'=>true,'staff_profile_id'=>null,'voucher_code'=>$v['promotionCode']??null,'loyalty_points'=>0];
        if (!$store && $r->filled('startAt')) {
            $r->validate(['startAt'=>'date']);
            $data['start_at']=CarbonImmutable::parse($r->startAt,'Asia/Ho_Chi_Minh')->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i');
        }
        if($store){$key=$r->header('Idempotency-Key');abort_unless(is_string($key)&&strlen($key)>=8&&strlen($key)<=100,422,'Thiếu mã xác nhận yêu cầu đặt lịch.');$phone=preg_replace('/[ ()-]/','',$v['contact']['phone']);abort_unless(preg_match('/^\+?[0-9]{8,15}$/D',$phone),422,'Số điện thoại không hợp lệ.');
            $start = CarbonImmutable::parse($v['startAt'], 'Asia/Ho_Chi_Minh')->setTimezone('Asia/Ho_Chi_Minh');
            abort_unless($start->second === 0 && $start->micro === 0, 422, 'Giờ hẹn phải chính xác đến phút.');
            $data+=['start_at'=>$start->format('Y-m-d\TH:i'),'idempotency_key'=>$key,'source'=>'ONLINE_WEB','contact_consent'=>true,'contact'=>['full_name'=>trim($v['contact']['name']),'phone'=>$phone,'email'=>$v['contact']['email']??null],'note'=>$v['note']??null,'violation_acknowledged'=>$v['violationAcknowledged']??false,'wallet_deposit_quote'=>$r->input('walletDepositQuote')];
        }
        return $data;
    }
}
