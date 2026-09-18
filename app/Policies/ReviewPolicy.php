<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    public function create(User $user, Booking $booking): bool
    {
        return $user->id === $booking->customer_id
            && $booking->status === 'completed'
            && !$booking->review;
    }

    public function update(User $user, Review $review): bool
    {
        return $user->id === $review->customer_id;
    }

    public function reply(User $user, Review $review): bool
    {
        return $user->id === $review->branch?->business?->owner_id;
    }
}
