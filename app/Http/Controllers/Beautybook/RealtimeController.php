<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Services\Beautybook\ApiScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RealtimeController extends Controller
{
    public function session(Request $r,ApiScope $scope): array
    {
        $a=$scope->current($r); $r->validate(['after'=>'nullable|integer|min:0']);
        $assignments=$scope->assignments($r);
        $query=DB::table('beautybook_realtime_events');
        match($a->role_name) {
            'PLATFORM_ADMIN'=>true,
            'BUSINESS_OWNER'=>$query->where('business_id',$a->business_id),
            'RECEPTIONIST','STAFF'=>$query->where('business_id',$a->business_id)->when(!$assignments->contains('role_name','BUSINESS_OWNER'), fn($q)=>$q->whereIn('branch_id',$assignments->pluck('branch_id')->filter()->unique()->all())),
            'CUSTOMER'=>$query->where('customer_user_id',$r->user()->id),
            default=>abort(403),
        };
        if($a->role_name==='BUSINESS_OWNER' && $a->branch_id) $query->where('branch_id',$a->branch_id);
        // Connect from the current committed cursor; reconnect fetches current
        // scheduler state, while established consumers receive durable events.
        $max=(int)DB::table('beautybook_realtime_events')->max('id');
        $rows=$r->has('after') ? $query->where('id','>',(int)$r->after)->where('id','<=',$max)->orderBy('id')->limit(200)->get() : collect();
        $cursor=$rows->count()===200 ? (int)$rows->last()->id : $max;
        $events=$rows->map(function($row) use($a,$assignments) {
            $counter=$assignments->contains(fn($grant)=>in_array($grant->role_name,['PLATFORM_ADMIN','BUSINESS_OWNER'],true) || ($grant->role_name==='RECEPTIONIST' && (string)$grant->branch_id===(string)$row->branch_id));
            return !$counter && $a->role_name!=='CUSTOMER' ? ['event'=>'scheduler_resync','payload'=>null] : ['event'=>$row->event,'payload'=>['id'=>(string)$row->booking_id,'branchId'=>(string)$row->branch_id,'status'=>$row->status,'updatedAt'=>\Carbon\CarbonImmutable::parse($row->created_at,'UTC')->toISOString()]];
        });
        $authorization=$assignments->map(fn($grant)=>implode(':',[$grant->id,$grant->role_name,$grant->business_id,$grant->branch_id,$grant->expires_at?->toIso8601String()]))->sort()->implode('|');
        return ['cursor'=>$cursor,'authorization'=>hash('sha256',$authorization),'validUntil'=>min((int)$r->session()->get('beautybook_access_expires',0),time()+15),'events'=>$events];
    }
}
