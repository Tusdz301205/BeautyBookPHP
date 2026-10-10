<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyRecord extends Model
{
    protected $fillable = [
        'actor_user_id', 'workspace_key', 'idempotency_key', 'payload_hash', 'status', 'booking_id', 'http_status',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
