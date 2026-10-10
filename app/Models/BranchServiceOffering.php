<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BranchServiceOffering extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id', 'branch_id', 'business_service_id', 'price', 'duration_minutes', 'bookable', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'bookable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'business_service_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ServiceVariant::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(StaffProfile::class, 'staff_services', 'branch_service_offering_id', 'staff_profile_id')
            ->withPivot(['business_id', 'branch_id'])
            ->withTimestamps();
    }

    public function priceRules(): HasMany
    {
        return $this->hasMany(ServicePriceRule::class);
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(ServiceDependency::class, 'branch_service_offering_id');
    }
}
