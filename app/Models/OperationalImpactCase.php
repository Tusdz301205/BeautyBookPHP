<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalImpactCase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'deadline_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OperationalImpactItem::class, 'case_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
