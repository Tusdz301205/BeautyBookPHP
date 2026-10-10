<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function view(User $user, Branch $branch): bool
    {
        return $user->roleAssignments()
            ->where(function ($query) use ($branch) {
                $query->where(function ($owner) use ($branch) {
                    $owner->where('role_name', RoleName::BusinessOwner->value)
                        ->where('business_id', $branch->business_id);
                })->orWhere(function ($scoped) use ($branch) {
                    $scoped->whereIn('role_name', [RoleName::Receptionist->value, RoleName::Staff->value])
                        ->where('business_id', $branch->business_id)
                        ->where('branch_id', $branch->id);
                })->orWhere('role_name', RoleName::PlatformAdmin->value);
            })
            ->exists();
    }

    public function operate(User $user, Branch $branch): bool
    {
        return $user->roleAssignments()
            ->whereIn('role_name', [RoleName::BusinessOwner->value, RoleName::Receptionist->value])
            ->where('business_id', $branch->business_id)
            ->where(function ($query) use ($branch) {
                $query->where('role_name', RoleName::BusinessOwner->value)
                    ->orWhere('branch_id', $branch->id);
            })
            ->exists();
    }

    public function manage(User $user, Branch $branch): bool
    {
        return $user->roleAssignments()
            ->where('role_name', RoleName::BusinessOwner->value)
            ->where('business_id', $branch->business_id)
            ->exists();
    }

    public function review(User $user, Branch $branch): bool
    {
        $isAdmin = $user->roleAssignments()
            ->where('role_name', RoleName::PlatformAdmin->value)
            ->exists();

        $isOwner = $user->roleAssignments()
            ->where('role_name', RoleName::BusinessOwner->value)
            ->where('business_id', $branch->business_id)
            ->exists();

        return $isAdmin && ! $isOwner;
    }
}
