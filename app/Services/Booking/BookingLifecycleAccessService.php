<?php

namespace App\Services\Booking;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Services\Identity\SessionContext;
use Illuminate\Http\Request;

class BookingLifecycleAccessService
{
    public function __construct(private SessionContext $sessionContext)
    {
    }

    public function resolve(Request $request, Booking $booking): array
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->account_kind === AccountKind::Customer) {
            $profile = CustomerProfile::query()->where('user_id', $user->id)->first();
            abort_unless($profile && (int) $booking->customer_profile_id === (int) $profile->id, 403);

            return [
                'actor_user_id' => $user->id,
                'kind' => 'CUSTOMER',
                'role_name' => RoleName::Customer->value,
                'workspace_assignment_id' => null,
                'customer_profile_id' => $profile->id,
                'staff_profile_id' => null,
            ];
        }

        $assignment = $this->sessionContext->current($request);
        abort_unless($assignment, 403);

        $context = [
            'actor_user_id' => $user->id,
            'role_name' => $assignment->role_name,
            'workspace_assignment_id' => $assignment->id,
            'customer_profile_id' => null,
            'staff_profile_id' => null,
        ];

        if ($assignment->role_name === RoleName::BusinessOwner->value) {
            abort_unless((int) $assignment->business_id === (int) $booking->business_id, 403);
            return [...$context, 'kind' => 'OWNER'];
        }

        if ($assignment->role_name === RoleName::Receptionist->value) {
            abort_unless(
                (int) $assignment->business_id === (int) $booking->business_id
                    && (int) $assignment->branch_id === (int) $booking->branch_id,
                403,
            );
            return [...$context, 'kind' => 'RECEPTIONIST'];
        }

        if ($assignment->role_name === RoleName::Staff->value) {
            abort_unless(
                (int) $assignment->business_id === (int) $booking->business_id
                    && (int) $assignment->branch_id === (int) $booking->branch_id,
                403,
            );
            $staff = StaffProfile::query()
                ->where('user_id', $user->id)
                ->where('business_id', $booking->business_id)
                ->where('branch_id', $booking->branch_id)
                ->where('status', 'ACTIVE')
                ->first();
            abort_unless($staff, 403);
            return [...$context, 'kind' => 'STAFF', 'staff_profile_id' => $staff->id];
        }

        if ($assignment->role_name === RoleName::PlatformAdmin->value) {
            return [...$context, 'kind' => 'ADMIN'];
        }

        abort(403);
    }

    public function assertCurrent(Booking $booking, array $context): void
    {
        if ($context['kind'] === 'CUSTOMER') {
            $profile = CustomerProfile::query()
                ->whereKey($context['customer_profile_id'])
                ->where('user_id', $context['actor_user_id'])
                ->lockForUpdate()
                ->first();
            abort_unless($profile && (int) $booking->customer_profile_id === (int) $profile->id, 403);
            return;
        }

        $assignment = RoleAssignment::query()
            ->whereKey($context['workspace_assignment_id'])
            ->where('user_id', $context['actor_user_id'])
            ->where('role_name', $context['role_name'])
            ->lockForUpdate()
            ->first();
        abort_unless($assignment, 403);

        if ($context['kind'] === 'ADMIN') {
            return;
        }

        abort_unless((int) $assignment->business_id === (int) $booking->business_id, 403);

        if (in_array($context['kind'], ['RECEPTIONIST', 'STAFF'], true)) {
            abort_unless((int) $assignment->branch_id === (int) $booking->branch_id, 403);
        }
    }
}
