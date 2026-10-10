<?php

namespace App\Models;

use App\Enums\BusinessStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    protected $fillable = [
        'owner_profile_id',
        'name',
        'slug',
        'description',
        'contact_email',
        'contact_phone',
        'address_line',
        'status',
        'review_note',
        'submitted_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BusinessStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function scopePublicReady(Builder $query): Builder
    {
        return $query->whereIn('status', [BusinessStatus::Approved->value, BusinessStatus::Active->value])->whereNull('booking_restricted_at');
    }

    public function ownerProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessOwnerProfile::class, 'owner_profile_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(BusinessService::class);
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(BusinessReviewEvent::class);
    }

    public function staffProfiles(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
    }
}
