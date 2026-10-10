<?php

namespace App\Services\Identity;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class RoleAssignmentService
{
    public function grant(
        User $user,
        RoleName $role,
        ?Business $business = null,
        ?Branch $branch = null,
        ?User $grantedBy = null,
        \DateTimeInterface|string|null $expiresAt = null,
    ): RoleAssignment {
        $expiry = $expiresAt === null ? null : CarbonImmutable::parse($expiresAt)->utc();
        if ($expiry && ! $expiry->isFuture()) {
            throw new DomainException('Hạn cấp quyền phải ở tương lai.');
        }

        return DB::transaction(function () use ($user, $role, $business, $branch, $grantedBy, $expiry) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            Role::query()->whereKey($role->value)->firstOrFail();

            $this->assertAccountKind($lockedUser, $role);
            $this->assertScope($role, $business, $branch);

            return RoleAssignment::query()->firstOrCreate([
                'user_id' => $lockedUser->id,
                'role_name' => $role->value,
                'scope_key' => $this->scopeKey($role, $business, $branch),
            ], [
                'business_id' => $business?->id,
                'branch_id' => $branch?->id,
                'granted_by_user_id' => $grantedBy?->id,
                'expires_at' => $expiry,
            ]);
        }, 3);
    }

    public function revoke(RoleAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            User::query()->lockForUpdate()->findOrFail($assignment->user_id);
            RoleAssignment::query()->whereKey($assignment->id)->delete();
            DB::table('sessions')->where('user_id', $assignment->user_id)->delete();
        }, 3);
    }

    public function scopeKey(RoleName $role, ?Business $business = null, ?Branch $branch = null): string
    {
        return match ($role) {
            RoleName::Customer => 'self',
            RoleName::PlatformAdmin => 'platform',
            RoleName::BusinessOwner => 'business:'.$business?->id,
            RoleName::Receptionist, RoleName::Staff => 'branch:'.$business?->id.':'.$branch?->id,
        };
    }

    private function assertAccountKind(User $user, RoleName $role): void
    {
        if ($role === RoleName::Customer && $user->account_kind !== AccountKind::Customer) {
            throw new DomainException('CUSTOMER role requires a CUSTOMER account.');
        }

        if ($role->requiresOperationalAccount() && $user->account_kind !== AccountKind::Operational) {
            throw new DomainException('Operational roles require an OPERATIONAL account.');
        }
    }

    private function assertScope(RoleName $role, ?Business $business, ?Branch $branch): void
    {
        if (in_array($role, [RoleName::Customer, RoleName::PlatformAdmin], true) && ($business || $branch)) {
            throw new DomainException('This role does not accept a business or branch scope.');
        }

        if ($role === RoleName::BusinessOwner && (! $business || $branch)) {
            throw new DomainException('BUSINESS_OWNER requires exactly one Business scope.');
        }

        if (in_array($role, [RoleName::Receptionist, RoleName::Staff], true)) {
            if (! $business || ! $branch) {
                throw new DomainException('Branch-scoped roles require both Business and Branch.');
            }

            if ($branch->business_id !== $business->id) {
                throw new DomainException('Branch must belong to the selected Business.');
            }
        }
    }
}
