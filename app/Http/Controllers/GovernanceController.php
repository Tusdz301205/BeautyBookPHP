<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Models\Business;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Platform\GovernanceService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GovernanceController extends Controller
{
    public function users(Request $request, GovernanceService $governance)
    {
        $governance->scope($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'role' => ['nullable', Rule::enum(RoleName::class)], 'status' => ['nullable', 'in:ACTIVE,SUSPENDED'], 'account_kind' => ['nullable', 'in:CUSTOMER,OPERATIONAL']]);
        $query = User::with('roleAssignments');
        if (! empty($filters['search'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')->orWhere('email', 'like', '%'.$filters['search'].'%'));
        }
        if (! empty($filters['role'])) {
            $query->whereHas('roleAssignments', fn ($q) => $q->where('role_name', $filters['role']));
        }
        if (! empty($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'ACTIVE');
        }
        if (! empty($filters['account_kind'])) {
            $query->where('account_kind', $filters['account_kind']);
        }
        $users = $query->latest()->paginate(20)->withQueryString();
        $summary = ['total' => User::count(), 'active' => User::where('is_active', true)->count(), 'suspended' => User::where('is_active', false)->count(), 'customers' => User::where('account_kind', 'CUSTOMER')->count(), 'operational' => User::where('account_kind', 'OPERATIONAL')->count()];

        return $request->expectsJson() ? response()->json(['users' => $users, 'summary' => $summary]) : view('governance.users', ['users' => $users, 'summary' => $summary]);
    }

    public function user(Request $request, User $user, GovernanceService $governance)
    {
        $governance->scope($request);
        $user->load(['roleAssignments.business', 'roleAssignments.branch', 'customerProfile']);
        $logs = DB::table('audit_logs')->where(fn ($q) => $q->where('user_id', $user->id)->orWhere(fn ($q) => $q->where('entity_type', 'User')->where('entity_id', (string) $user->id)))->latest('id')->limit(100)->get();

        return $request->expectsJson() ? response()->json(['user' => $user, 'audit' => $logs, 'sessions' => DB::table('sessions')->where('user_id', $user->id)->count()]) : view('governance.user', ['target' => $user, 'logs' => $logs, 'businesses' => Business::with('branches')->orderBy('name')->get()]);
    }

    public function status(Request $request, User $user, GovernanceService $governance)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:2000']]);

        return $this->run($request, fn () => $governance->status($request, $user, (bool) $data['is_active'], $data['reason']), 'Đã cập nhật trạng thái tài khoản.');
    }

    public function grantRole(Request $request, User $user, GovernanceService $governance)
    {
        $data = $request->validate(['role_name' => ['required', Rule::enum(RoleName::class)], 'business_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer']]);

        return $this->run($request, fn () => $governance->grantRole($request, $user, $data), 'Đã cấp vai trò và thu hồi phiên đăng nhập cũ.', 201);
    }

    public function revokeRole(Request $request, User $user, RoleAssignment $assignment, GovernanceService $governance)
    {
        return $this->run($request, fn () => $governance->revokeRole($request, $user, $assignment), 'Đã gỡ vai trò và thu hồi phiên đăng nhập cũ.');
    }

    public function trust(Request $request, GovernanceService $governance)
    {
        $governance->scope($request);
        $snapshots = DB::table('trust_snapshots')->join('businesses', 'businesses.id', '=', 'trust_snapshots.business_id')->select('trust_snapshots.*', 'businesses.name')->orderBy('trust_score')->paginate(20);
        $actions = DB::table('trust_actions')->join('businesses', 'businesses.id', '=', 'trust_actions.business_id')->select('trust_actions.*', 'businesses.name')->latest('trust_actions.id')->limit(100)->get();

        return $request->expectsJson() ? response()->json(['period_days' => 90, 'snapshots' => $snapshots, 'actions' => $actions]) : view('governance.trust', ['snapshots' => $snapshots, 'actions' => $actions, 'businesses' => Business::with('branches')->orderBy('name')->get()]);
    }

    public function rebuildTrust(Request $request, GovernanceService $governance)
    {
        $governance->scope($request);

        return $this->run($request, fn () => ['businesses' => $governance->rebuildTrust($request->user()->id)], 'Đã cập nhật snapshot trust 90 ngày.');
    }

    public function trustAction(Request $request, Business $business, GovernanceService $governance)
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'action' => ['required', 'in:WARNING_SENT,EXPLANATION_REQUESTED,MONITORING_STARTED,BOOKING_RESTRICTED,SUSPENDED,RESTORED,NOTE_ADDED'], 'reason' => ['required', 'string', 'max:2000'], 'internal_note' => ['nullable', 'string', 'max:4000']]);

        return $this->run($request, fn () => $governance->trustAction($request, $business, $data), 'Đã lưu quyết định trust.', 201);
    }

    public function dashboard(Request $request, GovernanceService $governance)
    {
        $scope = $governance->scope($request, ['PLATFORM_ADMIN', 'BUSINESS_OWNER']);
        $filters = $request->validate(['branch_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $data = $governance->dashboard($scope, array_filter($filters, fn ($v) => $v !== null));

        return $request->expectsJson() ? response()->json($data) : view('governance.dashboard', ['report' => $data]);
    }

    private function run(Request $request, callable $action, string $message, int $status = 200)
    {
        try {
            $result = $action();
            if (is_object($result) && isset($result->outcome) && $result->outcome !== 'APPLIED') {
                return $request->expectsJson() ? response()->json($result, 409) : back()->withErrors(['governance' => $result->message ?? 'Cần xử lý hồ sơ ảnh hưởng trước khi áp dụng quyết định.']);
            }

return $request->expectsJson() ? response()->json($result ?? ['message' => $message], $status) : back()->with('status', $message);
        } catch (DomainException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()],422) : back()->withErrors(['governance' => $e->getMessage()]);
        }
    }
}
