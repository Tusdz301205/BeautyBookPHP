<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

class StaffPolicy
{
    public function manage(User $user, Staff $staff): bool
    {
        return $user->id === $staff->branch?->business?->owner_id;
    }

    public function update(User $user, Staff $staff): bool
    {
        return $this->manage($user, $staff);
    }

    public function delete(User $user, Staff $staff): bool
    {
        return $this->manage($user, $staff);
    }
}
