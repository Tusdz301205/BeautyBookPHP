<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingService extends Model
{
    protected $fillable = [
        'booking_id', 'business_id', 'branch_id', 'branch_service_offering_id', 'business_service_id',
        'canonical_service_id', 'staff_profile_id', 'service_variant_id', 'price_at_booking', 'currency',
        'duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes', 'service_name_snapshot',
        'variant_name_snapshot', 'sort_order', 'status', 'item_start_at', 'item_end_at',
        'occupied_start_at', 'occupied_end_at', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'price_at_booking' => 'decimal:2',
            'item_start_at' => \App\Casts\UtcDateTime::class,
            'item_end_at' => \App\Casts\UtcDateTime::class,
            'occupied_start_at' => \App\Casts\UtcDateTime::class,
            'occupied_end_at' => \App\Casts\UtcDateTime::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(BranchServiceOffering::class, 'branch_service_offering_id');
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class);
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id');
    }
}
