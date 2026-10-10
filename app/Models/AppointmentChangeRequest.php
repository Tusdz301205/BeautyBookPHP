<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentChangeRequest extends Model
{
    protected $fillable = [
        'booking_id', 'requested_by_user_id', 'requested_by_type', 'request_type',
        'proposed_start_at', 'proposed_end_at', 'proposed_staff_profile_id', 'reason',
        'status', 'reviewed_by_user_id', 'reviewed_at', 'review_note', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_start_at' => 'datetime',
            'proposed_end_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function freshTimestamp()
    {
        return CarbonImmutable::now('UTC');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
