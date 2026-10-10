<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessReviewEvent extends Model
{
    protected $fillable = ['business_id', 'actor_id', 'action', 'from_status', 'to_status', 'reason'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
