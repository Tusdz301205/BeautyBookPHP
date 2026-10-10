<?php

namespace App\Http\Controllers;

use App\Enums\AccountKind;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomerProfile;
use App\Models\PaymentTransaction;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\CounterCustomerLookupService;
use App\Services\Finance\FinancialPolicyService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PackageService;
use App\Services\Finance\PaymentService;
use App\Services\Finance\SettlementService;
use App\Services\Identity\SessionContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvancedFinanceController extends Controller
{
    public function __construct(private FinanceScope $scope,private SessionContext $context,private FinancialPolicyService $policies,private PackageService $packages,private InvoiceService $invoices,private SettlementService $settlements) {}

    public function publicPackages(Request $request)
    {
        $data=$request->validate(['business_id'=>'nullable|integer','branch_id'=>'nullable|integer','search'=>'nullable|string|max:100']);
        $query=DB::table('treatment_packages')->where('status','ACTIVE')->whereIn('business_id',Business::publicReady()->select('id'));
        if(!empty($data['business_id']))$query->where('business_id',$data['business_id']);
        if(!empty($data['branch_id']))$query->where(fn($q)=>$q->where('branch_id',$data['branch_id'])->orWhereNull('branch_id'));
        if(!empty($data['search']))$query->where('name','like','%'.$data['search'].'%');
        return view('finance.packages',['packages'=>$query->latest()->paginate(20)->withQueryString()]);
    }

    public function index(Request $request)
    {
        $isCustomer=$request->user()->account_kind===AccountKind::Customer;
        $role='CUSTOMER'; $businesses=collect(); $branches=collect(); $customerId=null; $businessId=null;
        $bookingQuery=Booking::query(); $purchaseQuery=DB::table('package_purchases');
        if($isCustomer){
            $customerId=CustomerProfile::where('user_id',$request->user()->id)->value('id'); abort_unless($customerId,403);
            $bookingQuery->where('customer_profile_id',$customerId); $purchaseQuery->where('customer_profile_id',$customerId);
        }else{
            $assignment=$this->context->current($request); abort_unless($assignment&&in_array($assignment->role_name,['BUSINESS_OWNER','RECEPTIONIST','PLATFORM_ADMIN'],true),403);
            $role=$assignment->role_name;
            $businesses=$role==='PLATFORM_ADMIN'?Business::orderBy('name')->get():Business::whereKey($assignment->business_id)->get();
            $filter=$request->validate(['business_id'=>'nullable|integer']);
            $businessId=(int)($filter['business_id']??$businesses->first()?->id); abort_unless($businesses->contains('id',$businessId),403);
            $branches=Branch::where('business_id',$businessId)->when($assignment->branch_id,fn($q)=>$q->whereKey($assignment->branch_id))->get();
            $bookingQuery->where('business_id',$businessId); $purchaseQuery->where('business_id',$businessId);
            if($assignment->branch_id){$bookingQuery->where('branch_id',$assignment->branch_id);$purchaseQuery->where('branch_id',$assignment->branch_id);}
        }
        $bookingIds=$bookingQuery->pluck('id');
        $customers=collect();
        if(in_array($role,['BUSINESS_OWNER','RECEPTIONIST'],true)){
            $customers=CustomerProfile::whereHas('bookings',function($q)use($businessId,$assignment){
                $q->where('business_id',$businessId);if($assignment->branch_id)$q->where('branch_id',$assignment->branch_id);
            })->with('user')->get();
            $lookup=$request->session()->get('finance_customer_lookup.'.$assignment->id);
            if($lookup&&(int)$lookup['business_id']===$businessId&&(int)($lookup['branch_id']??0)===(int)($assignment->branch_id??0)&&($lookup['found_at']??0)>=now()->subMinutes(15)->timestamp){
                $matched=CustomerProfile::whereKey($lookup['customer_profile_id'])->whereHas('user',fn($q)=>$q->where('account_kind','CUSTOMER')->where('email',$lookup['email']))->with('user')->first();
                if($matched&&!$customers->contains('id',$matched->id))$customers->push($matched);
            }
        }
        $purchases=$purchaseQuery->latest()->paginate(15)->withQueryString();
        $installments=DB::table('package_installments')->whereIn('package_purchase_id',$purchases->pluck('id'))->orderBy('sequence')->get()->groupBy('package_purchase_id');
        $entitlements=DB::table('package_session_entitlements')->whereIn('package_purchase_id',$purchases->pluck('id'))->orderBy('sequence')->get()->groupBy('package_purchase_id');
        $packageTransactions=PaymentTransaction::whereIn('package_purchase_id',$purchases->pluck('id'))->with('reversal')->get()->groupBy('package_purchase_id');
        return view('finance.advanced',[
            'role'=>$role,'businesses'=>$businesses,'businessId'=>$businessId,'branches'=>$branches,'purchases'=>$purchases,'installments'=>$installments,'entitlements'=>$entitlements,'packageTransactions'=>$packageTransactions,
            'policies'=>$businessId?DB::table('payment_policies')->where('business_id',$businessId)->latest()->get():collect(),
            'packages'=>$businessId?DB::table('treatment_packages')->where('business_id',$businessId)->get():collect(),
            'statements'=>$businessId&&in_array($role,['BUSINESS_OWNER','PLATFORM_ADMIN'],true)?DB::table('platform_statements')->where('business_id',$businessId)->latest()->get():collect(),
            'invoices'=>$role==='PLATFORM_ADMIN'?collect():DB::table('invoices')->whereIn('booking_id',$bookingIds)->latest()->limit(100)->get(),
            'invoiceRequests'=>$role==='PLATFORM_ADMIN'?collect():DB::table('invoice_information_requests')->whereIn('booking_id',$bookingIds)->latest()->limit(100)->get(),
            'customerBookings'=>$isCustomer?$bookingQuery->with('services')->get():collect(),
            'customers'=>$customers,
        ]);
    }

    public function policy(Request $request,Business $business)
    {
        $data=$request->validate(['name'=>'required|string|max:160','branch_id'=>'nullable|integer','business_service_id'=>'nullable|integer',
            'deposit_type'=>'required|in:NONE,FIXED,PERCENTAGE,FULL_PREPAYMENT','deposit_value'=>$this->amountRules(),'allow_split_payment'=>'required|boolean','allow_installments'=>'required|boolean',
            'effective_from'=>'required|date','effective_to'=>'nullable|date|after_or_equal:effective_from']);
        return $this->respond($request,fn()=>DB::table('payment_policies')->find($this->policies->create($request,$business->id,$data)),201);
    }
    public function lookupCustomer(Request $request,CounterCustomerLookupService $lookup)
    {
        $data=$request->validate(['email'=>'required|email|max:255']);
        $customer=$lookup->lookup($request,$data['email']);
        return $request->expectsJson()?response()->json($customer):redirect()->route('finance.advanced')->with('status','Đã tìm thấy '.$customer['name'].'. Bạn có thể chọn khách trong biểu mẫu đăng ký liệu trình.');
    }
    public function createPackage(Request $request,Business $business)
    {
        $data=$request->validate(['name'=>'required|string|max:160','description'=>'nullable|string|max:2000','branch_id'=>'nullable|integer','total_price'=>$this->amountRules(),'session_count'=>'required|integer|between:1,100','validity_days'=>'required|integer|between:1,3650']);
        return $this->respond($request,fn()=>$this->packages->create($request,$business->id,$data),201);
    }
    public function purchase(Request $request,int $package)
    {
        $data=$request->validate(['customer_profile_id'=>'required|integer','branch_id'=>'nullable|integer','installment_count'=>'required|integer|between:1,12']);
        return $this->respond($request,fn()=>$this->packages->purchase($request,$package,$data),201);
    }
    public function payInstallment(Request $request,int $installment)
    {
        $data=$request->validate(['method'=>'required|in:CASH,BANK_TRANSFER','idempotency_key'=>'required|string|max:200','evidence'=>'nullable|array']);
        return $this->respond($request,fn()=>$this->packages->pay($request,$installment,$data));
    }
    public function packageTransaction(Request $request,PaymentTransaction $transaction,string $action)
    {
        abort_unless(in_array($action,['verify','reverse'],true),404);
        $data=$request->validate($action==='verify'?['settlement_reference'=>'required|string|max:200','evidence'=>'required|array|min:1']:['reason'=>'required|string|max:1000','idempotency_key'=>'required|string|max:200']);
        return $this->respond($request,fn()=>$action==='verify'?$this->packages->verify($request,$transaction,$data):$this->packages->reverse($request,$transaction,$data));
    }
    public function reserve(Request $request,int $purchase)
    {
        $data=$request->validate(['booking_id'=>'required|integer','booking_service_id'=>'required|integer']);
        return $this->respond($request,fn()=>$this->packages->reserve($request,$purchase,$data));
    }
    public function statement(Request $request,Business $business)
    {
        $data=$request->validate(['period_start'=>'required|date_format:Y-m-d','period_end'=>'required|date_format:Y-m-d|after_or_equal:period_start']);
        return $this->respond($request,fn()=>$this->settlements->generate($request,$business->id,$data));
    }
    public function transitionStatement(Request $request,int $statement)
    {
        $data=$request->validate(['status'=>'required|in:REVIEW,ISSUED,PAID,OVERDUE','reason'=>'required|string|max:1000']);
        return $this->respond($request,fn()=>$this->settlements->transition($request,$statement,$data));
    }
    public function invoiceRequest(Request $request,Booking $booking)
    {
        $data=$request->validate(['buyer_name'=>'required|string|max:160','buyer_email'=>'nullable|email|max:255','buyer_tax_code'=>'nullable|string|max:80','buyer_address'=>'nullable|string|max:500','note'=>'nullable|string|max:1000','idempotency_key'=>'required|string|max:200']);
        return $this->respond($request,fn()=>$this->invoices->requestInformation($request,$booking,$data),201);
    }
    public function resolveInvoiceRequest(Request $request,int $invoiceRequest,string $action)
    {
        abort_unless(in_array($action,['cancel','reject'],true),404);
        $data=$request->validate(['reason'=>$action==='reject'?'required|string|max:1000':'nullable|string|max:1000']);
        return $this->respond($request,fn()=>$this->invoices->resolveRequest($request,$invoiceRequest,$action==='cancel',$data['reason']??null));
    }
    public function issueInvoice(Request $request,Booking $booking)
    {
        $data=$request->validate(['tax_rate'=>['nullable','regex:/^\d{1,3}(?:\.\d{1,2})?$/D'],'tax_inclusive'=>'sometimes|boolean','buyer_name'=>'nullable|string|max:160','buyer_email'=>'nullable|email','buyer_tax_code'=>'nullable|string|max:80','buyer_address'=>'nullable|string|max:500','invoice_request_id'=>'nullable|integer']);
        return $this->respond($request,fn()=>$this->invoices->issue($request,$booking,$data),201);
    }
    public function changeInvoice(Request $request,int $invoice,string $action)
    {
        abort_unless(in_array($action,['cancel','reissue'],true),404);
        $data=$request->validate(['reason'=>'required|string|max:1000','buyer_name'=>'nullable|string|max:160','buyer_email'=>'nullable|email','buyer_tax_code'=>'nullable|string|max:80','buyer_address'=>'nullable|string|max:500']);
        return $this->respond($request,fn()=>$this->invoices->change($request,$invoice,$action==='reissue',$data));
    }
    public function invoice(Request $request,int $invoice)
    {
        $invoice=DB::table('invoices')->find($invoice);abort_unless($invoice,404);
        $booking=Booking::findOrFail($invoice->booking_id);$role=app(PaymentService::class)->access($request,$booking);abort_if($role==='PLATFORM_ADMIN',403);
        return view('finance.invoice',['invoice'=>$invoice,'booking'=>$booking,'role'=>$role,'buyer'=>json_decode($invoice->buyer_snapshot,true),'seller'=>json_decode($invoice->seller_snapshot,true),'lines'=>json_decode($invoice->lines,true),'events'=>DB::table('invoice_events')->where('invoice_id',$invoice->id)->get()]);
    }
    private function amountRules(): array{return ['required','regex:/^\d{1,12}(?:\.\d{1,2})?$/D'];}
    private function respond(Request $request,callable $callback,int $status=200)
    {
        try{$row=$callback();}catch(UniqueConstraintViolationException){abort(409,'Mã yêu cầu hoặc chứng từ đã được sử dụng.');}
        return $request->expectsJson()?response()->json(['id'=>$row->id,'status'=>$row->status??'CREATED'],$status):redirect()->route('finance.advanced')->with('status','Đã cập nhật nghiệp vụ tài chính.');
    }
}
