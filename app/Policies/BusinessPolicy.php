<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function view(User $user, Business $business): bool
    {
        return $user->roleAssignments()
            ->where(function ($query) use ($business) {
                $query->where(function ($owner) use ($business) {
                    $owner->where('role_name', RoleName::BusinessOwner->value)
                        ->where('business_id', $business->id);
                })->orWhere('role_name', RoleName::PlatformAdmin->value);
            })
            ->exists();
    }

    public function manage(User $user, Business $business): bool
    {
        return $user->roleAssignments()
            ->where('role_name', RoleName::BusinessOwner->value)
            ->where('business_id', $business->id)
            ->exists();
    }

    public function review(User $user, Business $business): bool
    {
        $isAdmin = $user->roleAssignments()
            ->where('role_name', RoleName::PlatformAdmin->value)
            ->exists();

        $isOwner = $user->roleAssignments()
            ->where('role_name', RoleName::BusinessOwner->value)
            ->where('business_id', $business->id)
            ->exists();

        return $isAdmin && ! $isOwner;
    }
}
