<?php

namespace App\Services\Beautybook;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomerProfile;
use App\Models\RoleAssignment;
use App\Services\Identity\SessionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Requested headers select context; the current server-side assignment grants access. */
class ApiScope
{
    public function __construct(private SessionContext $context) {}

    /** Live grants in the authenticated workspace and business; resource checks retain each grant's branch. */
    public function assignments(Request $request): Collection
    {
        abort_unless($request->user(), 401);
        $anchor = $this->context->current($request);
        if (! $anchor && $request->session()->get('workspace_assignment_id')) {
            $anchor = RoleAssignment::whereKey($request->session()->get('workspace_assignment_id'))->where('user_id', $request->user()->id)->first();
        }
        abort_unless($anchor, 403, 'Phiên không còn quyền trong không gian hiện tại.');
        $roles = match ($anchor->role_name) {
            'CUSTOMER' => ['CUSTOMER'],
            'PLATFORM_ADMIN' => ['PLATFORM_ADMIN'],
            default => ['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF'],
        };
        $assignments = $request->user()->roleAssignments()->whereIn('role_name', $roles)->where('business_id', $anchor->business_id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))->orderBy('id')->get();
        abort_unless($assignments->isNotEmpty(), 403, 'Phiên không còn quyền trong không gian hiện tại.');

        return $assignments;
    }

    public function forRoles(Request $request, array $roles, mixed $businessId = null, mixed $branchId = null): RoleAssignment
    {
        $this->current($request);
        $assignment = $this->assignments($request)->sortBy(fn ($a) => array_search($a->role_name, $roles, true))->first(fn ($a) => in_array($a->role_name, $roles, true)
            && ($businessId === null || $a->role_name === 'PLATFORM_ADMIN' || (string) $a->business_id === (string) $businessId)
            && ($branchId === null || in_array($a->role_name, ['BUSINESS_OWNER', 'PLATFORM_ADMIN'], true) || (string) $a->branch_id === (string) $branchId));
        abort_unless($assignment, 403);

        return $assignment;
    }

    public function requestForRoles(Request $request, array $roles, mixed $businessId = null, mixed $branchId = null): Request
    {
        $assignment = $this->forRoles($request, $roles, $businessId, $branchId);
        $copy = clone $request;
        $session = clone $request->session();
        $session->put('workspace_assignment_id', $assignment->id);
        $copy->setLaravelSession($session);

        return $copy;
    }

    public function current(Request $request): RoleAssignment
    {
        abort_unless($request->user(), 401);
        $assignments = $this->assignments($request);
        $assignment = $assignments->firstWhere('id', $request->session()->get('workspace_assignment_id')) ?? $assignments->first();
        $selectedBranch = $request->session()->get('beautybook_context_branch');
        if ($selectedBranch && in_array($assignment->role_name, ['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF'], true)) {
            abort_unless(Branch::whereKey($selectedBranch)->where('business_id', $assignment->business_id)->exists(), 403);
            abort_unless($assignments->contains(fn ($a) => $a->role_name === 'BUSINESS_OWNER' || (string) $a->branch_id === (string) $selectedBranch), 403);
        }
        $business = $request->header('X-Business-Id');
        $branch = $request->header('X-Branch-Id');
        if ($assignment->role_name !== 'PLATFORM_ADMIN') {
            abort_if($business && (string) $assignment->business_id !== (string) $business, 403);
            if ($branch && in_array($assignment->role_name, ['BUSINESS_OWNER', 'STAFF', 'RECEPTIONIST'], true)) {
                abort_unless($assignments->contains(fn ($a) => $a->role_name === 'BUSINESS_OWNER' || (string) $a->branch_id === (string) $branch), 403);
                abort_unless(Branch::whereKey($branch)->where('business_id', $assignment->business_id)->exists(), 403);
            }
        }

        return $assignment;
    }

    public function branch(Request $request, mixed $id): Branch
    {
        $assignment = $this->current($request);
        $branch = Branch::findOrFail($id);
        if (in_array($assignment->role_name, ['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF'], true)) {
            $assignment = $this->forRoles($request, ['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF'], $branch->business_id, $branch->id);
        }
        match ($assignment->role_name) {
            'PLATFORM_ADMIN' => true,
            'CUSTOMER' => abort_unless(Branch::publicReady()->whereKey($branch->id)->exists(), 404),
            'BUSINESS_OWNER' => abort_unless((string) $branch->business_id === (string) $assignment->business_id, 403),
            'RECEPTIONIST', 'STAFF' => abort_unless((string) $branch->id === (string) $assignment->branch_id && (string) $branch->business_id === (string) $assignment->business_id, 403),
            default => abort(403),
        };

        return $branch;
    }

    public function owner(Request $request, mixed $businessId = null): Business
    {
        $assignment = $this->forRoles($request, ['BUSINESS_OWNER'], $businessId);
        $id = $businessId ?? $assignment->business_id;
        abort_unless($id && (string) $id === (string) $assignment->business_id, 403);

        return Business::findOrFail($id);
    }

    public function customer(Request $request): CustomerProfile
    {
        abort_unless($this->current($request)->role_name === 'CUSTOMER', 403, 'Vui lòng dùng tài khoản khách hàng riêng.');

        return $request->user()->customerProfile()->firstOrFail();
    }

    public function platform(Request $request): RoleAssignment
    {
        $assignment = $this->current($request);
        abort_unless($assignment->role_name === 'PLATFORM_ADMIN', 403);

        return $assignment;
    }

    public function paginate($query, Request $request, callable $presenter): array
    {
        $limit = max(1, min(100, (int) $request->input('limit', $request->input('pageSize', 20))));
        $result = $query->paginate($limit, ['*'], 'page', max(1, (int) $request->input('page', 1)));
        $pagination = ['total' => $result->total(), 'page' => $result->currentPage(), 'limit' => $limit, 'totalPages' => $result->lastPage()];

        return ['data' => array_map($presenter, $result->items()), ...$pagination, 'pagination' => $pagination];
    }
}
