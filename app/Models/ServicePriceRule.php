<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePriceRule extends Model
{
    protected $fillable = [
        'branch_service_offering_id', 'service_variant_id', 'name', 'priority', 'adjustment_type',
        'adjustment_value', 'conditions', 'active', 'valid_from', 'valid_to', 'version',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_value' => 'decimal:2',
            'conditions' => 'array',
            'active' => 'boolean',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(BranchServiceOffering::class, 'branch_service_offering_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id');
    }
}
