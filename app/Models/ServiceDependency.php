<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceDependency extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'branch_service_offering_id', 'required_offering_id', 'dependency_type',
    ];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(BranchServiceOffering::class, 'branch_service_offering_id');
    }

    public function requiredOffering(): BelongsTo
    {
        return $this->belongsTo(BranchServiceOffering::class, 'required_offering_id');
    }
}
