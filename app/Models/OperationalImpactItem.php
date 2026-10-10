<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalImpactItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['booking_snapshot' => 'array', 'resolved_at' => 'immutable_datetime', 'proposed_start_at' => 'immutable_datetime'];
    }

    public function impactCase(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OperationalImpactCase::class, 'case_id');
    }

    public function booking(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
