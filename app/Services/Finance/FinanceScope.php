<?php

namespace App\Services\Finance;

use App\Enums\AccountKind;
use App\Services\Identity\SessionContext;
use App\Models\RoleAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class FinanceScope
{
    public function __construct(private SessionContext $context) {}
    public function business(Request $request, int $businessId, array $roles, ?int $branchId = null): string
    {
        abort_unless($request->user()?->account_kind === AccountKind::Operational, 403);
        $assignment = $this->context->current($request);
        if ($assignment && DB::transactionLevel() > 0) $assignment = RoleAssignment::whereKey($assignment->id)->where('user_id',$request->user()->id)->lockForUpdate()->first();
        abort_unless($assignment && in_array($assignment->role_name, $roles, true), 403);
        if ($assignment->role_name !== 'PLATFORM_ADMIN') {
            abort_unless((int) $assignment->business_id === $businessId, 403);
            if ($assignment->branch_id) abort_unless($branchId && (int) $assignment->branch_id === $branchId, 403);
        }
        return $assignment->role_name;
    }
}
