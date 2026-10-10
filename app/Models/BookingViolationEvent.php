<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingViolationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'customer_profile_id', 'business_id', 'source_request_id', 'kind',
        'occurred_at', 'appointment_start_at', 'recorded_by_user_id', 'policy_version',
        'voided_at', 'voided_by_user_id', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'appointment_start_at' => 'datetime',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
