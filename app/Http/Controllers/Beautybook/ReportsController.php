<?php
namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Services\Beautybook\{ApiScope,ReportService};
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function report(Request $r,string $kind,ReportService $reports)
    {
        return match($kind) {
            'overview'=>$reports->overview($r),'revenue'=>$reports->revenue($r),'categories'=>$reports->categories($r),'services'=>$reports->services($r),'top-salons'=>$reports->topBranches($r),'user-growth'=>$reports->userGrowth($r),'staff-performance'=>$reports->staff($r),'owner-dashboard'=>$reports->ownerDashboard($r),'financial-summary'=>$reports->financial($r),default=>abort(404),
        };
    }
    public function financeOverview(Request $r,ApiScope $scope,ReportService $reports): array
    {
        $scope->platform($r); $ledger=$reports->ledger($reports->branches($r)); $cash=$reports->cash($ledger); $pending=\Illuminate\Support\Facades\DB::table('payment_transactions')->where('status','PENDING'); return $cash+['paid'=>['total'=>$cash['grossRevenue'],'count'=>$ledger->where('type','PAYMENT_RECEIVED')->count()],'refunded'=>['total'=>$cash['refundAmount']],'pending'=>['total'=>(int)(clone $pending)->sum('amount_cents')/100,'count'=>(clone $pending)->count()]];
    }
}
