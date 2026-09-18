<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_id',
        'name',
        'slug',
        'description',
        'logo',
        'phone',
        'email',
        'website',
        'tax_code',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Business $business) {
            if (empty($business->slug)) {
                $business->slug = Str::slug($business->name) . '-' . Str::random(6);
            }
        });
    }

    /* ========== Relations ========== */

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function serviceCategories()
    {
        return $this->hasMany(ServiceCategory::class);
    }

    public function categories()
    {
        return $this->hasMany(ServiceCategory::class);
    }

    /* ========== Scopes ========== */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /* ========== Accessors ========== */

    public function getLogoUrlAttribute(): string
    {
        if ($this->logo) {
            return asset('storage/' . $this->logo);
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=8b5cf6&color=fff&size=128';
    }

    /**
     * Tính average rating cho tất cả branches của business.
     */
    public function getAverageRatingAttribute(): float
    {
        return (float) Review::whereIn('branch_id', $this->branches()->pluck('id'))
            ->where('status', 'approved')
            ->avg('overall_rating') ?? 0;
    }

    public function getTotalReviewsAttribute(): int
    {
        return Review::whereIn('branch_id', $this->branches()->pluck('id'))
            ->where('status', 'approved')
            ->count();
    }
}
