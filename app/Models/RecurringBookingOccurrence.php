<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringBookingOccurrence extends Model
{
    protected $fillable = ['recurring_booking_series_id', 'booking_id', 'sequence', 'scheduled_start_at', 'status', 'reason'];

    protected function casts(): array
    {
        return ['scheduled_start_at' => 'immutable_datetime'];
    }

    public function series(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RecurringBookingSeries::class, 'recurring_booking_series_id');
    }

    public function booking(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
