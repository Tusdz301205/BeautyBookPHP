<?php

namespace App\Services\Finance;

use App\Enums\AccountKind;
use App\Models\Booking;
use App\Models\Business;
use App\Models\CustomerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private PaymentService $payments, private FinanceScope $scope) {}

    public function requestInformation(Request $request, Booking $booking, array $data): object
    {
        abort_unless($request->user()->account_kind === AccountKind::Customer, 403);
        return DB::transaction(function () use ($request, $booking, $data) {
            $booking = $this->lockBooking($booking->id); $this->payments->access($request, $booking);
            $customer = CustomerProfile::where('user_id', $request->user()->id)->firstOrFail();
            $prior = DB::table('invoice_information_requests')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($prior) { abort_unless((int) $prior->customer_profile_id === (int) $customer->id && (int) $prior->booking_id === (int) $booking->id, 409); return $prior; }
            $open = DB::table('invoice_information_requests')->where('open_key', $booking->id)->first(); if ($open) return $open;
            abort_if(DB::table('invoices')->where('booking_id', $booking->id)->where('status','ISSUED')->exists(), 409);
            abort_if(in_array($booking->status,['CANCELLED','REJECTED','EXPIRED'],true),409);
            abort_unless($this->payments->balance($booking)['net_cents'] >= Money::cents($booking->total_amount), 409, 'Lịch chưa được thu đủ.');
            $id = DB::table('invoice_information_requests')->insertGetId(['booking_id' => $booking->id, 'customer_profile_id' => $customer->id,
                'buyer_snapshot' => json_encode($this->buyer($data), JSON_THROW_ON_ERROR), 'note' => $data['note'] ?? null, 'open_key' => $booking->id,
                'idempotency_key' => $data['idempotency_key'], 'created_at' => now(), 'updated_at' => now()]);
            return DB::table('invoice_information_requests')->find($id);
        }, 3);
    }

    public function resolveRequest(Request $request, int $id, bool $cancel, ?string $reason): object
    {
        return DB::transaction(function () use ($request, $id, $cancel, $reason) {
            $reference = DB::table('invoice_information_requests')->find($id); abort_unless($reference,404);
            $booking = $this->lockBooking($reference->booking_id);
            $row = DB::table('invoice_information_requests')->where('id',$id)->lockForUpdate()->first();
            if ($cancel) {
                abort_unless($request->user()->account_kind === AccountKind::Customer,403); $this->payments->access($request,$booking);
            } else $this->scope->business($request,$booking->business_id,['BUSINESS_OWNER'],$booking->branch_id);
            abort_unless($row->status === 'PENDING',409);
            DB::table('invoice_information_requests')->where('id',$id)->update(['status'=>$cancel?'CANCELLED':'REJECTED','open_key'=>null,'resolution_note'=>$reason,
                'resolved_by'=>$cancel?null:$request->user()->id,'resolved_at'=>now(),'updated_at'=>now()]);
            return DB::table('invoice_information_requests')->find($id);
        },3);
    }

    public function issue(Request $request, Booking $booking, array $data): object
    {
        return DB::transaction(function () use ($request,$booking,$data) {
            $booking = $this->lockBooking($booking->id);
            $this->scope->business($request,$booking->business_id,['BUSINESS_OWNER','RECEPTIONIST'],$booking->branch_id);
            $requestRow = !empty($data['invoice_request_id']) ? DB::table('invoice_information_requests')->where('id',$data['invoice_request_id'])->where('booking_id',$booking->id)->where('status','PENDING')->first() : null;
            abort_if(!empty($data['invoice_request_id']) && !$requestRow,409);
            $existing = DB::table('invoices')->where('booking_id',$booking->id)->where('status','ISSUED')->first();
            if ($existing) { if ($requestRow) $this->fulfill($requestRow->id,$existing->id,$request->user()->id); return $existing; }
            $balance = $this->payments->balance($booking); abort_unless($balance['net_cents'] >= $balance['total_cents'],409,'Lịch chưa được thu đủ.');
            $snapshot = $booking->pricingSnapshot;
            $subtotal = $snapshot ? Money::cents($snapshot->subtotal_amount) : Money::cents($booking->total_amount);
            $discount = max(0,$subtotal - $balance['total_cents']); $rate = Money::cents($data['tax_rate']??'0'); abort_if($rate>10000,422);
            $inclusive = $data['tax_inclusive']??true; $tax = $this->tax($balance['total_cents'],$rate,$inclusive);
            $buyer = !empty($data['buyer_name']) ? $this->buyer($data) : ($requestRow ? json_decode($requestRow->buyer_snapshot,true) : ['name'=>$booking->customerProfile?->user?->name,'email'=>$booking->customerProfile?->user?->email,'phone'=>$booking->customerProfile?->user?->phone]);
            $items = $booking->services; $itemSum = (int)$items->sum(fn($i)=>Money::cents($i->price_at_booking)); $assigned=0; $assignedTax=0; $remainingPrice=$itemSum; $lines=[];
            foreach($items as $index=>$item) {
                $price=Money::cents($item->price_at_booking);
                $remainingPrice-=$price; $remainingDiscount=$discount-$assigned;
                $lineDiscount=$index === $items->count()-1 ? $remainingDiscount : ($itemSum ? Money::multiplyDivideRound($discount,$price,$itemSum):0);
                // Keep rounding residuals within the remaining lines' price capacity.
                $lineDiscount=min($price,$remainingDiscount,max($lineDiscount,$remainingDiscount-$remainingPrice)); $assigned+=$lineDiscount;
                $net=$price-$lineDiscount;
                // The header is authoritative: independently rounded taxes can exceed it.
                $lineTax=$index === $items->count()-1 ? $tax-$assignedTax : min($tax-$assignedTax,$this->tax($net,$rate,$inclusive)); $assignedTax+=$lineTax;
                $lines[]=['booking_service_id'=>$item->id,'description'=>$item->service_name_snapshot,'quantity'=>1,'unit_price_cents'=>$price,'discount_cents'=>$lineDiscount,'tax_cents'=>$lineTax,'total_cents'=>$inclusive?$net:$net+$lineTax];
            }
            $id=DB::table('invoices')->insertGetId(['business_id'=>$booking->business_id,'booking_id'=>$booking->id,'invoice_number'=>$this->number($booking->business_id),
                'subtotal_cents'=>$subtotal,'discount_cents'=>$discount,'tax_cents'=>$tax,'total_cents'=>$inclusive?$balance['total_cents']:$balance['total_cents']+$tax,
                'tax_rate_basis_points'=>$rate,'tax_inclusive'=>$inclusive,'buyer_snapshot'=>json_encode($buyer,JSON_THROW_ON_ERROR),
                'seller_snapshot'=>json_encode(['business_name'=>$booking->branch->business->name,'branch_name'=>$booking->branch->name,'address'=>$booking->branch->address_line],JSON_THROW_ON_ERROR),
                'lines'=>json_encode($lines,JSON_THROW_ON_ERROR),'created_by'=>$request->user()->id,'issued_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            $invoice=DB::table('invoices')->find($id); $this->event($request,$invoice,'ISSUE');
            if($requestRow) $this->fulfill($requestRow->id,$id,$request->user()->id);
            return $invoice;
        },3);
    }

    public function change(Request $request,int $id,bool $reissue,array $data): object
    {
        return DB::transaction(function()use($request,$id,$reissue,$data){
            $reference=DB::table('invoices')->find($id); abort_unless($reference,404);
            Business::whereKey($reference->business_id)->lockForUpdate()->firstOrFail();
            $invoice=DB::table('invoices')->where('id',$id)->lockForUpdate()->first();
            $this->scope->business($request,$invoice->business_id,['BUSINESS_OWNER']);
            abort_unless($invoice->status==='ISSUED' && !DB::table('invoices')->where('replaces_invoice_id',$id)->exists(),409);
            if($reissue){
                $copy=(array)$invoice; unset($copy['id']); $copy['invoice_number']=$this->number($invoice->business_id); $copy['replaces_invoice_id']=$id; $copy['version']=$invoice->version+1;
                $copy['created_by']=$request->user()->id; $copy['issued_at']=now(); $copy['created_at']=now(); $copy['updated_at']=now();
                if(!empty($data['buyer_name']))$copy['buyer_snapshot']=json_encode($this->buyer($data),JSON_THROW_ON_ERROR);
                $newId=DB::table('invoices')->insertGetId($copy); $replacement=DB::table('invoices')->find($newId); $this->event($request,$replacement,'ISSUE_REPLACEMENT',$data['reason']);
            }
            DB::table('invoices')->where('id',$id)->update(['status'=>$reissue?'ADJUSTED':'CANCELLED','cancelled_at'=>now(),'updated_at'=>now()]);
            $this->event($request,DB::table('invoices')->find($id),$reissue?'REISSUE':'CANCEL',$data['reason']);
            return $reissue?$replacement:DB::table('invoices')->find($id);
        },3);
    }

    private function lockBooking(int $id): Booking
    {
        $booking=Booking::findOrFail($id); Business::whereKey($booking->business_id)->lockForUpdate()->firstOrFail();
        return Booking::whereKey($id)->lockForUpdate()->firstOrFail();
    }
    private function number(int $businessId): string
    {
        $year=now('UTC')->year; $sequence=DB::table('invoices')->where('business_id',$businessId)->whereYear('created_at',$year)->count()+1;
        return 'BB-'.$year.'-'.str_pad((string)$sequence,7,'0',STR_PAD_LEFT);
    }
    private function tax(int $net,int $rate,bool $inclusive): int
    {
        if(!$rate)return 0; $denominator=$inclusive?10000+$rate:10000;
        // Source invoices round tax to whole VND, while preserving exact cents for payments.
        return Money::multiplyDivideRound($net,$rate,$denominator*100)*100;
    }
    private function buyer(array $data): array { return ['name'=>trim($data['buyer_name']),'email'=>$data['buyer_email']??null,'tax_code'=>$data['buyer_tax_code']??null,'address'=>$data['buyer_address']??null]; }
    private function fulfill(int $requestId,int $invoiceId,int $actor): void
    {
        DB::table('invoice_information_requests')->where('id',$requestId)->update(['status'=>'FULFILLED','invoice_id'=>$invoiceId,'open_key'=>null,'resolved_by'=>$actor,'resolved_at'=>now(),'updated_at'=>now()]);
    }
    private function event(Request $request,object $invoice,string $action,?string $reason=null): void
    {
        DB::table('invoice_events')->insert(['invoice_id'=>$invoice->id,'actor_id'=>$request->user()->id,'action'=>$action,'reason'=>$reason,'snapshot'=>json_encode($invoice,JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
}
