<?php

namespace App\Services\Beautybook;

use App\Enums\AccountKind;
use App\Models\Branch;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Resolve only after credential verification, before issuing an authenticated session. */
class LoginContext
{
    private const WORKSPACES = [
        'CUSTOMER' => ['CUSTOMER'],
        'SALON' => ['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF'],
        'PLATFORM' => ['PLATFORM_ADMIN'],
    ];

    public function resolve(User $user, array $request = []): array
    {
        abort_unless($user->is_active, 401, 'Email hoặc mật khẩu không đúng');
        $workspace = $request['workspace'] ?? null;
        if ($workspace !== null && (! is_string($workspace) || ! array_key_exists($workspace, self::WORKSPACES))) {
            $this->challenge(['message' => 'Không gian làm việc không hợp lệ']);
        }
        foreach (['businessId', 'branchId'] as $field) {
            if (isset($request[$field]) && (! is_scalar($request[$field]) || ! preg_match('/^[1-9]\d*$/', (string) $request[$field]))) {
                $this->challenge(['message' => 'Phạm vi đăng nhập không hợp lệ']);
            }
        }
        $all = $user->roleAssignments()->with(['business', 'branch'])->orderBy('id')->get();
        $active = $all->filter(fn (RoleAssignment $assignment) => $this->usable($user, $assignment));
        $operational = $all->contains(fn ($assignment) => $assignment->role_name !== 'CUSTOMER' && $this->unexpired($assignment));
        $available = array_values(array_filter(array_keys(self::WORKSPACES), fn ($name) => ($name !== 'CUSTOMER' || ! $operational) && $active->contains(fn ($a) => in_array($a->role_name, self::WORKSPACES[$name], true))));
        abort_unless($available, 401, 'Tài khoản chưa có không gian làm việc hợp lệ');
        if ($workspace === null && count($available) > 1) {
            $this->challenge(['message' => 'Chọn không gian bạn muốn sử dụng để tiếp tục', 'code' => 'WORKSPACE_REQUIRED', 'availableWorkspaces' => $available]);
        }
        $workspace ??= $available[0];
        abort_unless(in_array($workspace, $available, true), 401, 'Tài khoản không có quyền truy cập không gian đã chọn');
        $assignments = $active->filter(fn ($a) => in_array($a->role_name, self::WORKSPACES[$workspace], true))->values();
        $businessId = $branchId = null;
        if ($workspace === 'SALON') {
            $businesses = $assignments->pluck('business_id')->filter()->map(fn ($id) => (string) $id)->unique()->values();
            $businessId = isset($request['businessId']) ? (string) $request['businessId'] : ($businesses->count() === 1 ? $businesses->first() : null);
            if ($businessId === null && $businesses->count() > 1) {
                $this->challenge(['message' => 'Hãy chọn doanh nghiệp cần làm việc', 'code' => 'BUSINESS_REQUIRED', 'businessIds' => $businesses->all(),
                    'businesses' => $assignments->unique('business_id')->map(fn ($a) => ['id' => (string) $a->business_id, 'name' => $a->business->name])->values()->all()]);
            }
            abort_unless($businessId !== null && $businesses->contains($businessId), 401, 'Không có quyền truy cập doanh nghiệp đã chọn');
            $assignments = $assignments->filter(fn ($a) => (string) $a->business_id === $businessId)->values();
            $branchId = isset($request['branchId']) ? (string) $request['branchId'] : null;
            if ($branchId !== null) {
                $branch = Branch::whereKey($branchId)->where('business_id', $businessId)->first();
                abort_unless($branch && ! $branch->getAttribute('deleted_at'), 401, 'Không có quyền truy cập chi nhánh đã chọn');
                abort_unless($assignments->contains(fn ($a) => ($a->role_name === 'BUSINESS_OWNER' && $a->branch_id === null) || (string) $a->branch_id === $branchId), 401, 'Không có quyền truy cập chi nhánh đã chọn');
            }
        }
        // Laravel authorizes the selected assignment, never a union of unrelated scopes.
        $priority = ['BUSINESS_OWNER' => 0, 'RECEPTIONIST' => 1, 'STAFF' => 2];
        $assignment = $assignments->sortBy(fn ($a) => $priority[$a->role_name] ?? 0)->first();

        return compact('workspace', 'businessId', 'branchId', 'assignments', 'assignment') + ['availableWorkspaces' => $available];
    }

    private function unexpired(RoleAssignment $assignment): bool
    {
        return ! $assignment->getAttribute('revoked_at') && (! $assignment->getAttribute('expires_at') || CarbonImmutable::parse($assignment->expires_at)->isFuture());
    }

    private function usable(User $user, RoleAssignment $a): bool
    {
        if (! $this->unexpired($a)) {
            return false;
        }
        if ($a->role_name === 'CUSTOMER') {
            return $user->account_kind === AccountKind::Customer && $a->business_id === null && $a->branch_id === null;
        }
        if ($user->account_kind !== AccountKind::Operational) {
            return false;
        }
        if ($a->role_name === 'PLATFORM_ADMIN') {
            return $a->business_id === null && $a->branch_id === null;
        }
        if (! $a->business || $a->business->getAttribute('deleted_at')) {
            return false;
        }
        if ($a->role_name === 'BUSINESS_OWNER') {
            return $a->branch_id === null;
        }

        return in_array($a->role_name, self::WORKSPACES['SALON'], true) && $a->branch && ! $a->branch->getAttribute('deleted_at') && (int) $a->branch->business_id === (int) $a->business_id;
    }

    private function challenge(array $body): never
    {
        throw new HttpResponseException(response()->json($body, 400));
    }
}
