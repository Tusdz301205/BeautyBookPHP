<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\StaffProfile;
use Illuminate\Http\Request;

class WorkspaceHomeController extends Controller
{
    public function __invoke(Request $request)
    {
        $workspace = $request->attributes->get('workspace');
        $query = Booking::query();
        if ($workspace->role_name === 'CUSTOMER') {
            $query->where('customer_profile_id', $request->user()->customerProfile?->id ?? -1);
        } elseif ($workspace->role_name === 'PLATFORM_ADMIN') {
            // Platform totals are read-only here; mutations retain their domain gates.
        } elseif (in_array($workspace->role_name, ['BUSINESS_OWNER','RECEPTIONIST','STAFF'], true)) {
            $query->where('business_id', $workspace->business_id ?? -1);
            if ($workspace->role_name !== 'BUSINESS_OWNER') $query->where('branch_id', $workspace->branch_id ?? -1);
            if ($workspace->role_name === 'STAFF') {
                $staff = StaffProfile::where('user_id', $request->user()->id)->where('business_id', $workspace->business_id)->where('branch_id', $workspace->branch_id)->where('status','ACTIVE')->first();
                $query->whereHas('services', fn ($items) => $items->where('staff_profile_id', $staff?->id ?? -1));
            }
        } else {
            abort(403);
        }
        $todayStart = now()->startOfDay()->utc();
        $todayEnd = now()->endOfDay()->utc();
        $metrics = [
            'Lịch hôm nay' => (clone $query)->whereBetween('appointment_start_at',[$todayStart,$todayEnd])->count(),
            'Sắp tới' => (clone $query)->whereIn('status',['PENDING','CONFIRMED','CHECKED_IN','IN_PROGRESS'])->where('appointment_end_at','>=',now()->utc())->count(),
            'Hoàn thành' => (clone $query)->where('status','COMPLETED')->count(),
            'Đã hủy' => (clone $query)->whereIn('status',['CANCELLED','REJECTED','EXPIRED'])->count(),
        ];
        $upcoming = (clone $query)->with(['branch','services'])->whereIn('status',['PENDING','CONFIRMED','CHECKED_IN','IN_PROGRESS'])->where('appointment_end_at','>=',now()->utc())->orderBy('appointment_start_at')->limit(6)->get();
        $bookingBranch = null;
        if (in_array($workspace->role_name,['BUSINESS_OWNER','RECEPTIONIST'],true)) {
            $bookingBranch = Branch::where('business_id',$workspace->business_id)->when($workspace->branch_id,fn ($q)=>$q->whereKey($workspace->branch_id))->orderBy('id')->first();
        }
        return view('app',compact('workspace','metrics','upcoming','bookingBranch'));
    }
}
