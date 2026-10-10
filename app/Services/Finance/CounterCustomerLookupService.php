<?php

namespace App\Services\Finance;

use App\Models\Business;
use App\Models\CustomerProfile;
use App\Services\Identity\SessionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CounterCustomerLookupService
{
    public function __construct(private SessionContext $context, private FinanceScope $scope) {}

    public function lookup(Request $request, string $email): array
    {
        $assignment = $this->context->current($request);
        abort_unless($assignment && in_array($assignment->role_name,['BUSINESS_OWNER','RECEPTIONIST'],true),403);
        $email = mb_strtolower(trim($email));
        return DB::transaction(function()use($request,$assignment,$email){
            Business::whereKey($assignment->business_id)->lockForUpdate()->firstOrFail();
            $this->scope->business($request,$assignment->business_id,['BUSINESS_OWNER','RECEPTIONIST'],$assignment->branch_id);
            $customer = CustomerProfile::whereHas('user',fn($q)=>$q->where('account_kind','CUSTOMER')->whereRaw('LOWER(email) = ?',[$email]))->with('user')->first();
            abort_unless($customer,404,'Không tìm thấy tài khoản khách hàng với email này.');
            $request->session()->put('finance_customer_lookup.'.$assignment->id,[
                'customer_profile_id'=>$customer->id,'email'=>$customer->user->email,'business_id'=>$assignment->business_id,
                'branch_id'=>$assignment->branch_id,'found_at'=>now()->timestamp,
            ]);
            DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'business_id'=>$assignment->business_id,'action'=>'CUSTOMER_LOOKUP','entity_type'=>'CustomerProfile','entity_id'=>(string)$customer->id,
                'metadata'=>json_encode(['workspace_assignment_id'=>$assignment->id,'reference_hash'=>hash('sha256',$email)],JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return ['customer_profile_id'=>$customer->id,'name'=>$customer->user->name,'email'=>$customer->user->email];
        },3);
    }
}
