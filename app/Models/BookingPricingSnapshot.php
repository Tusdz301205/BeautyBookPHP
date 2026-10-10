<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPricingSnapshot extends Model
{
    protected $fillable = [
        'booking_id', 'currency', 'subtotal_amount', 'discount_amount', 'final_amount', 'quote_version', 'source_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'source_snapshot' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
