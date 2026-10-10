<?php

namespace App\Services\Booking;

use App\Enums\AccountKind;
use App\Enums\BranchOperationalStatus;
use App\Enums\BranchReviewStatus;
use App\Enums\BranchStatus;
use App\Enums\BusinessStatus;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomerProfile;
use App\Models\RoleAssignment;
use App\Services\Identity\SessionContext;
use DomainException;
use Illuminate\Http\Request;

class BookingAccessService
{
    public function __construct(private SessionContext $sessionContext) {}

    public function resolve(Request $request, Branch $branch): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->account_kind === AccountKind::Customer) {
            abort_unless(
                Branch::query()->publicReady()->whereKey($branch->id)->exists(),
                404,
            );

            $profile = CustomerProfile::query()->where('user_id', $user->id)->first();
            abort_unless($profile, 403);

            return [
                'actor_user_id' => $user->id,
                'kind' => 'CUSTOMER',
                'role_name' => RoleName::Customer->value,
                'workspace_key' => 'customer:'.$profile->id,
                'workspace_assignment_id' => null,
                'business_id' => null,
                'branch_id' => null,
                'customer_profile_id' => $profile->id,
            ];
        }

        $workspace = $this->sessionContext->current($request);
        abort_unless($workspace, 403);

        if ($workspace->role_name === RoleName::BusinessOwner->value) {
            abort_unless($workspace->business_id === $branch->business_id, 403);
            abort_unless($this->acceptsOperationalBookings($branch), 409);

            return [
                'actor_user_id' => $user->id,
                'kind' => 'OWNER',
                'role_name' => $workspace->role_name,
                'workspace_key' => 'assignment:'.$workspace->id,
                'workspace_assignment_id' => $workspace->id,
                'business_id' => $workspace->business_id,
                'branch_id' => null,
                'customer_profile_id' => null,
            ];
        }

        if ($workspace->role_name === RoleName::Receptionist->value) {
            abort_unless(
                $workspace->business_id === $branch->business_id && $workspace->branch_id === $branch->id,
                403,
            );
            abort_unless($this->acceptsOperationalBookings($branch), 409);

            return [
                'actor_user_id' => $user->id,
                'kind' => 'RECEPTIONIST',
                'role_name' => $workspace->role_name,
                'workspace_key' => 'assignment:'.$workspace->id,
                'workspace_assignment_id' => $workspace->id,
                'business_id' => $workspace->business_id,
                'branch_id' => $workspace->branch_id,
                'customer_profile_id' => null,
            ];
        }

        abort(403);
    }

    public function assertCurrentBranch(Branch $branch, Business $business, array $context): void
    {
        $this->assertCurrentBookableState($branch, $business, $context['kind'] === 'CUSTOMER');

        if ($context['kind'] === 'CUSTOMER') {
            return;
        }

        $assignment = RoleAssignment::query()
            ->whereKey($context['workspace_assignment_id'])
            ->where('user_id', $context['actor_user_id'])
            ->where('role_name', $context['role_name'])
            ->lockForUpdate()
            ->first();

        abort_unless($assignment, 403);

        if ($context['kind'] === 'OWNER') {
            abort_unless(
                (int) $assignment->business_id === (int) $branch->business_id
                    && $assignment->branch_id === null,
                403,
            );

            return;
        }

        abort_unless(
            $context['kind'] === 'RECEPTIONIST'
                && (int) $assignment->business_id === (int) $branch->business_id
                && (int) $assignment->branch_id === (int) $branch->id,
            403,
        );
    }

    private function assertCurrentBookableState(Branch $branch, Business $business, bool $publicCustomer): void
    {
        $branchReady = $branch->status === BranchStatus::Active
            && $branch->operational_status === BranchOperationalStatus::Active;
        $businessReady = in_array($business->status, [BusinessStatus::Approved, BusinessStatus::Active], true) && $business->booking_restricted_at === null;
        $reviewReady = ! $publicCustomer || $branch->review_status === BranchReviewStatus::Approved;

        if (! $branchReady || ! $businessReady || ! $reviewReady) {
            throw new DomainException('Chi nhánh hiện không nhận đặt lịch.');
        }
    }

    private function acceptsOperationalBookings(Branch $branch): bool
    {
        if (
            $branch->status !== BranchStatus::Active
            || $branch->operational_status !== BranchOperationalStatus::Active
        ) {
            return false;
        }

        $business = $branch->business()->first();

        return $business !== null
            && $business->booking_restricted_at === null
            && in_array($business->status, [BusinessStatus::Approved, BusinessStatus::Active], true);
    }
}
