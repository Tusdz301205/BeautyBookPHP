<?php

namespace App\Services\Identity;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\RoleAssignment;
use Illuminate\Http\Request;

class SessionContext
{
    public function current(Request $request): ?RoleAssignment
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        if ($user->account_kind === AccountKind::Customer) {
            return $user->roleAssignments()
                ->where('role_name', RoleName::Customer->value)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
                ->first();
        }

        $assignmentId = $request->session()->get('workspace_assignment_id');

        if (! $assignmentId) {
            return null;
        }

        return RoleAssignment::query()
            ->whereKey($assignmentId)
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
            ->first();
    }

    public function select(Request $request, RoleAssignment $assignment): void
    {
        abort_unless($request->user()?->account_kind === AccountKind::Operational, 403);
        abort_unless($assignment->user_id === $request->user()?->id, 403);
        abort_if($assignment->role_name === RoleName::Customer->value, 403);
        abort_if($assignment->expires_at && ! $assignment->expires_at->isFuture(), 403, 'Quyền làm việc đã hết hạn.');

        $request->session()->put('workspace_assignment_id', $assignment->id);
    }

    public function selectInitial(Request $request): void
    {
        if ($request->user()?->account_kind !== AccountKind::Operational) {
            $request->session()->forget('workspace_assignment_id');

            return;
        }

        $assignment = $request->user()->roleAssignments()->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))->orderBy('id')->first();

        if ($assignment) {
            $this->select($request, $assignment);
        } else {
            $request->session()->forget('workspace_assignment_id');
        }
    }
}
