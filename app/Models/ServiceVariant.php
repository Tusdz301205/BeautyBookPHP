<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_service_offering_id', 'code', 'name', 'description', 'price_type', 'price', 'max_price',
        'duration_minutes', 'max_duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes',
        'consultation_required', 'eligibility_rules', 'status', 'version',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'max_price' => 'decimal:2',
            'consultation_required' => 'boolean',
            'eligibility_rules' => 'array',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(BranchServiceOffering::class, 'branch_service_offering_id');
    }

    public function priceRules(): HasMany
    {
        return $this->hasMany(ServicePriceRule::class);
    }
}
