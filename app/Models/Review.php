<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean', 'service_ratings' => 'array'];
    }

    public function booking() { return $this->belongsTo(Booking::class); }
    public function customerProfile() { return $this->belongsTo(CustomerProfile::class); }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', 'APPROVED')->whereHas('booking', fn ($booking) =>
            $booking->whereIn('business_id', Business::publicReady()->select('id')));
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class)->orderBy('sort_order');
    }

    public function photoData(bool $public = false): array
    {
        return $this->photos->map(fn (ReviewPhoto $photo) => $photo->data($public))->all();
    }
}
