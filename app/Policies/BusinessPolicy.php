<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function update(User $user, Business $business): bool
    {
        return $user->id === $business->owner_id;
    }

    public function manage(User $user, Business $business): bool
    {
        return $user->id === $business->owner_id;
    }
}
