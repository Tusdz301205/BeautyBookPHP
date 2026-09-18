<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'district_id',
        'name',
        'slug',
        'address',
        'phone',
        'email',
        'latitude',
        'longitude',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Branch $branch) {
            if (empty($branch->slug)) {
                $branch->slug = Str::slug($branch->name) . '-' . Str::random(6);
            }
        });
    }

    /* ========== Relations ========== */

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function images()
    {
        return $this->hasMany(BranchImage::class)->orderBy('sort_order');
    }

    public function workingHours()
    {
        return $this->hasMany(BranchWorkingHour::class)->orderBy('day_of_week');
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function staff()
    {
        return $this->hasMany(Staff::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /* ========== Scopes ========== */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /* ========== Accessors ========== */

    public function getCoverImageUrlAttribute(): ?string
    {
        $firstImage = $this->images->first();
        return $firstImage ? asset('storage/' . $firstImage->image_path) : null;
    }

    public function getAverageRatingAttribute(): float
    {
        return (float) ($this->reviews()->where('status', 'approved')->avg('overall_rating') ?? 0);
    }

    public function getReviewCountAttribute(): int
    {
        return $this->reviews()->where('status', 'approved')->count();
    }

    /**
     * Kiểm tra branch có đang mở cửa hay không.
     */
    public function getIsOpenNowAttribute(): bool
    {
        $now = now();
        $dayOfWeek = $now->dayOfWeekIso - 1; // 0=Mon, 6=Sun
        $currentTime = $now->format('H:i:s');

        $hours = $this->workingHours->firstWhere('day_of_week', $dayOfWeek);

        if (!$hours || $hours->is_closed) {
            return false;
        }

        return $currentTime >= $hours->open_time && $currentTime <= $hours->close_time;
    }
}
