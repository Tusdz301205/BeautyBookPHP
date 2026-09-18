<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $user->id === $booking->customer_id
            || $user->id === $booking->branch?->business?->owner_id;
    }

    public function cancel(User $user, Booking $booking): bool
    {
        if ($user->id === $booking->customer_id) {
            return in_array($booking->status, ['pending', 'confirmed']);
        }

        return $user->id === $booking->branch?->business?->owner_id;
    }

    public function updateStatus(User $user, Booking $booking): bool
    {
        return $user->id === $booking->branch?->business?->owner_id;
    }
}
