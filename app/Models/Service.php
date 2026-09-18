<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'discount_price',
        'duration_minutes',
        'status',
        'is_bookable',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'discount_price' => 'integer',
            'duration_minutes' => 'integer',
            'is_bookable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Service $service) {
            if (empty($service->slug)) {
                $service->slug = Str::slug($service->name) . '-' . Str::random(4);
            }
        });
    }

    /* ========== Relations ========== */

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function images()
    {
        return $this->hasMany(ServiceImage::class)->orderBy('sort_order');
    }

    public function coverImage()
    {
        return $this->hasOne(ServiceImage::class)->orderBy('sort_order');
    }

    public function staff()
    {
        return $this->belongsToMany(Staff::class, 'staff_services');
    }

    public function savedByUsers()
    {
        return $this->belongsToMany(User::class, 'saved_services')
                    ->withTimestamps();
    }

    /* ========== Scopes ========== */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeBookable($query)
    {
        return $query->where('is_bookable', true)->active();
    }

    /* ========== Accessors ========== */

    public function getCoverImageUrlAttribute(): ?string
    {
        $firstImage = $this->images->first();
        return $firstImage ? asset('storage/' . $firstImage->image_path) : null;
    }

    /**
     * Giá hiển thị (đã trừ discount nếu có).
     */
    public function getDisplayPriceAttribute(): int
    {
        return $this->discount_price ?? $this->price;
    }

    public function getEffectivePriceAttribute(): int
    {
        return $this->display_price;
    }

    /**
     * Format giá tiền VND.
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->display_price, 0, ',', '.') . '₫';
    }

    public function getHasDiscountAttribute(): bool
    {
        return $this->discount_price !== null && $this->discount_price < $this->price;
    }
}
