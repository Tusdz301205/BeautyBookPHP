<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_code',
        'customer_id',
        'branch_id',
        'appointment_date',
        'total_duration_minutes',
        'total_price',
        'status',
        'cancelled_by',
        'cancel_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'datetime',
            'total_price' => 'integer',
            'total_duration_minutes' => 'integer',
        ];
    }

    /**
     * Booking status machine — cho phép chuyển trạng thái.
     * Lấy từ BeautyBook gốc (bookings.validation.ts) và đơn giản hóa.
     */
    public const STATUS_TRANSITIONS = [
        'pending'     => ['confirmed', 'cancelled'],
        'confirmed'   => ['in_progress', 'cancelled', 'no_show'],
        'in_progress' => ['completed', 'cancelled'],
        'completed'   => [],
        'cancelled'   => [],
        'no_show'     => [],
    ];

    public const STATUS_LABELS = [
        'pending'     => 'Chờ xác nhận',
        'confirmed'   => 'Đã xác nhận',
        'in_progress' => 'Đang thực hiện',
        'completed'   => 'Hoàn thành',
        'cancelled'   => 'Đã hủy',
        'no_show'     => 'Không đến',
    ];

    public const STATUS_COLORS = [
        'pending'     => 'yellow',
        'confirmed'   => 'blue',
        'in_progress' => 'indigo',
        'completed'   => 'green',
        'cancelled'   => 'red',
        'no_show'     => 'gray',
    ];

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (empty($booking->booking_code)) {
                $booking->booking_code = 'BB-' . strtoupper(Str::random(8));
            }
        });
    }

    /**
     * Kiểm tra có thể chuyển sang trạng thái mới không.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::STATUS_TRANSITIONS[$this->status] ?? [];
        return in_array($newStatus, $allowed);
    }

    /**
     * Chuyển trạng thái + ghi history.
     */
    public function transitionTo(string $newStatus, ?int $changedBy = null, ?string $note = null): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            return false;
        }

        $oldStatus = $this->status;
        $this->status = $newStatus;
        $this->save();

        $this->statusHistories()->create([
            'from_status' => $oldStatus,
            'to_status'   => $newStatus,
            'changed_by'  => $changedBy,
            'note'        => $note,
        ]);

        return true;
    }

    /* ========== Relations ========== */

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function bookingServices()
    {
        return $this->hasMany(BookingService::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(BookingStatusHistory::class)->orderByDesc('created_at');
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }

    /* ========== Scopes ========== */

    public function scopeUpcoming($query)
    {
        return $query->where('appointment_date', '>=', now())
                     ->whereIn('status', ['pending', 'confirmed']);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /* ========== Accessors ========== */

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'gray';
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->total_price, 0, ',', '.') . '₫';
    }

    public function getIsTerminalAttribute(): bool
    {
        return in_array($this->status, ['completed', 'cancelled', 'no_show']);
    }
}
