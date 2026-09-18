<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function manage(User $user, Service $service): bool
    {
        return $user->id === $service->branch?->business?->owner_id;
    }

    public function update(User $user, Service $service): bool
    {
        return $this->manage($user, $service);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->manage($user, $service);
    }
}
