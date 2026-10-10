<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingContact extends Model
{
    protected $fillable = ['booking_id', 'full_name', 'phone', 'email'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
