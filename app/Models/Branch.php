<?php

namespace App\Models;

use App\Enums\BranchOperationalStatus;
use App\Enums\BranchReviewStatus;
use App\Enums\BranchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'public_name',
        'description',
        'address_line',
        'phone',
        'email',
        'timezone',
        'status',
        'review_status',
        'operational_status',
        'review_note',
        'submitted_at',
        'reviewed_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'review_status' => BranchReviewStatus::class,
            'operational_status' => BranchOperationalStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function scopePublicReady(Builder $query): Builder
    {
        return $query
            ->where('status', BranchStatus::Active->value)
            ->where('review_status', BranchReviewStatus::Approved->value)
            ->where('operational_status', BranchOperationalStatus::Active->value)
            ->whereHas('business', fn (Builder $business) => $business->publicReady());
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(BranchWorkingHour::class)->orderBy('day_of_week');
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(BranchServiceOffering::class);
    }

    public function stateTransitions(): HasMany
    {
        return $this->hasMany(BranchStateTransition::class);
    }

    public function staffProfiles(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
