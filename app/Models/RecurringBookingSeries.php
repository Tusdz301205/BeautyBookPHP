<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringBookingSeries extends Model
{
    protected $table = 'recurring_booking_series';

    protected $fillable = ['customer_profile_id', 'branch_id', 'idempotency_key', 'payload_hash', 'frequency', 'staff_mode', 'status', 'start_date', 'end_date', 'preferred_time', 'occurrence_count', 'request_snapshot'];

    protected function casts(): array
    {
        return ['request_snapshot' => 'array', 'start_date' => 'immutable_date', 'end_date' => 'immutable_date'];
    }

    public function occurrences(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RecurringBookingOccurrence::class)->orderBy('sequence');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
