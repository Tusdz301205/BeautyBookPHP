<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function manage(User $user, Branch $branch): bool
    {
        return $user->id === $branch->business?->owner_id;
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->manage($user, $branch);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->manage($user, $branch);
    }
}
