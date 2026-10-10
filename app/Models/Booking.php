<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id', 'branch_id', 'customer_profile_id', 'created_by_user_id', 'booking_code',
        'appointment_start_at', 'appointment_end_at', 'status', 'source', 'pending_expires_at',
        'currency', 'total_amount', 'note',
    ];

    protected function casts(): array
    {
        return [
            'appointment_start_at' => \App\Casts\UtcDateTime::class,
            'appointment_end_at' => \App\Casts\UtcDateTime::class,
            'pending_expires_at' => 'datetime',
            'total_amount' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(BookingService::class);
    }

    public function contact(): HasOne
    {
        return $this->hasOne(BookingContact::class);
    }

    public function pricingSnapshot(): HasOne
    {
        return $this->hasOne(BookingPricingSnapshot::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->orderBy('created_at');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(AppointmentChangeRequest::class)->latest('created_at');
    }
}
