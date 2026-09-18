<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_id',
        'customer_id',
        'branch_id',
        'overall_rating',
        'comment',
        'is_anonymous',
        'status',
        'business_reply',
        'replied_at',
    ];

    protected function casts(): array
    {
        return [
            'overall_rating' => 'integer',
            'is_anonymous' => 'boolean',
            'replied_at' => 'datetime',
        ];
    }

    /* ========== Relations ========== */

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /* ========== Scopes ========== */

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /* ========== Accessors ========== */

    /**
     * Tên hiển thị — ẩn danh nếu customer chọn anonymous.
     * Logic từ BeautyBook gốc (publicReviewerIdentity).
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->is_anonymous) {
            return 'Ẩn danh';
        }

        return $this->customer->name ?? 'Ẩn danh';
    }

    public function getHasReplyAttribute(): bool
    {
        return !empty($this->business_reply);
    }
}
