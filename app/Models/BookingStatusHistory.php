<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatusHistory extends Model
{
    protected $fillable = [
        'booking_id',
        'from_status',
        'to_status',
        'changed_by',
        'note',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
