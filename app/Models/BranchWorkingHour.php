<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchWorkingHour extends Model
{
    protected $fillable = ['branch_id', 'day_of_week', 'open_time', 'close_time', 'is_closed'];

    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Tên ngày tiếng Việt.
     */
    public function getDayNameAttribute(): string
    {
        $days = ['Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ Nhật'];
        return $days[$this->day_of_week] ?? '';
    }
}
