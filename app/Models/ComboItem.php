<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComboItem extends Model
{
    protected $fillable = ['combo_id', 'branch_service_offering_id', 'quantity', 'sort_order', 'transition_minutes', 'price_snapshot', 'duration_snapshot'];

    protected function casts(): array
    {
        return ['price_snapshot' => 'decimal:2'];
    }

    public function offering(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BranchServiceOffering::class, 'branch_service_offering_id');
    }

    public function combo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }
}
