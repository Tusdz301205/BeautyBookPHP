<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessService extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id', 'service_category_id', 'canonical_service_id', 'name', 'slug', 'description', 'keywords',
        'mapping_status', 'base_price', 'base_duration_minutes', 'status', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function canonicalService(): BelongsTo
    {
        return $this->belongsTo(CanonicalService::class);
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(BranchServiceOffering::class);
    }
}
