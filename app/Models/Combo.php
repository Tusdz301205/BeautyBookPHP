<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Combo extends Model
{
    use SoftDeletes;

    protected $fillable = ['business_id', 'branch_id', 'name', 'description', 'combo_price', 'pricing_mode', 'staff_assignment_mode', 'status', 'valid_from', 'valid_to', 'max_usage', 'used_count', 'version'];

    protected $appends = ['original_price', 'duration_minutes', 'saving_amount', 'discount_percentage'];

    protected function casts(): array
    {
        return ['combo_price' => 'decimal:2', 'valid_from' => 'immutable_datetime', 'valid_to' => 'immutable_datetime'];
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ComboItem::class)->orderBy('sort_order');
    }

    public function getOriginalPriceAttribute(): float
    {
        return $this->items->sum(fn ($item) => (float) $item->price_snapshot * $item->quantity);
    }

    public function getDurationMinutesAttribute(): int
    {
        return $this->items->sum(fn ($item) => $item->duration_snapshot * $item->quantity + $item->transition_minutes);
    }

    public function getSavingAmountAttribute(): float
    {
        return max(0, $this->original_price - (float) $this->combo_price);
    }

    public function getDiscountPercentageAttribute(): float
    {
        return $this->original_price > 0 ? round($this->saving_amount / $this->original_price * 100, 2) : 0;
    }
}
