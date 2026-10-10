<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPricingSnapshot;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceArithmeticTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $owner=User::where('email','owner@beautybook.local')->firstOrFail();
        $assignment=$owner->roleAssignments()->where('role_name','BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id'=>$assignment->id]);
    }

    public function test_inclusive_and_exclusive_line_taxes_equal_the_rounded_header(): void
    {
        foreach ([[true,'50.00','100.00',900,[500,400]], [false,'5.00','10.00',100,[100,0]]] as [$inclusive,$price,$total,$tax,$lineTaxes]) {
            $invoice=$this->issue([$price,$price],$total,'10.00',$inclusive);
            $lines=json_decode($invoice->lines,true,512,JSON_THROW_ON_ERROR);
            $this->assertSame($tax,(int)$invoice->tax_cents);
            $this->assertSame($lineTaxes,array_column($lines,'tax_cents'));
            $this->assertSame((int)$invoice->tax_cents,array_sum(array_column($lines,'tax_cents')));
            $this->assertSame((int)$invoice->total_cents,array_sum(array_column($lines,'total_cents')));
            $this->assertSame((int)$invoice->subtotal_cents,array_sum(array_column($lines,'unit_price_cents')));
        }
    }

    public function test_discount_rounding_never_makes_a_line_negative_and_matches_header(): void
    {
        $invoice=$this->issue(['0.01','0.01','0.01','0.01'],'0.02','0.00',true,'0.04');
        $lines=json_decode($invoice->lines,true,512,JSON_THROW_ON_ERROR);
        $this->assertSame(2,(int)$invoice->discount_cents);
        $this->assertSame((int)$invoice->discount_cents,array_sum(array_column($lines,'discount_cents')));
        $this->assertSame((int)$invoice->total_cents,array_sum(array_column($lines,'total_cents')));
        foreach ($lines as $line) {
            $this->assertGreaterThanOrEqual(0,$line['discount_cents']);
            $this->assertLessThanOrEqual($line['unit_price_cents'],$line['discount_cents']);
            $this->assertGreaterThanOrEqual(0,$line['tax_cents']);
            $this->assertGreaterThanOrEqual(0,$line['total_cents']);
        }
    }

    private function issue(array $prices,string $total,string $rate,bool $inclusive,?string $subtotal=null): object
    {
        $customer=User::where('email','customer@beautybook.local')->firstOrFail();
        $branch=Branch::where('slug','tan-tao')->firstOrFail();
        $offering=BranchServiceOffering::where('branch_id',$branch->id)->firstOrFail();
        $start=now()->subDay(); $end=$start->copy()->addHour();
        $booking=Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,
            'customer_profile_id'=>CustomerProfile::where('user_id',$customer->id)->value('id'),'created_by_user_id'=>$customer->id,
            'booking_code'=>'INV-'.uniqid(),'appointment_start_at'=>$start,'appointment_end_at'=>$end,
            'status'=>'COMPLETED','source'=>'ONLINE_WEB','currency'=>'VND','total_amount'=>$total]);
        foreach ($prices as $index=>$price) {
            $booking->services()->create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,
                'branch_service_offering_id'=>$offering->id,'business_service_id'=>$offering->business_service_id,
                'price_at_booking'=>$price,'currency'=>'VND','duration_minutes'=>60,'service_name_snapshot'=>'Invoice service '.$index,
                'item_start_at'=>$start,'item_end_at'=>$end,'occupied_start_at'=>$start,'occupied_end_at'=>$end]);
        }
        if ($subtotal!==null) BookingPricingSnapshot::create(['booking_id'=>$booking->id,'currency'=>'VND',
            'subtotal_amount'=>$subtotal,'discount_amount'=>'0.02','final_amount'=>$total,'quote_version'=>'test','source_snapshot'=>[]]);
        $this->send(route('finance.collect',$booking),['method'=>'CASH','amount'=>$total,'idempotency_key'=>'invoice-paid-'.$booking->id])->assertCreated();
        $id=$this->send(route('finance.invoices.store',$booking),['tax_rate'=>$rate,'tax_inclusive'=>$inclusive])->assertCreated()->json('id');
        return DB::table('invoices')->find($id);
    }

    private function send(string $url,array $data)
    {
        return $this->withSession(['_token'=>'invoice-arithmetic-csrf'])->withHeader('X-CSRF-TOKEN','invoice-arithmetic-csrf')->postJson($url,$data);
    }
}
