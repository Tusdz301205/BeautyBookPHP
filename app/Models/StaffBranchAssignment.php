<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffBranchAssignment extends Model
{
    protected $fillable = [
        'staff_profile_id', 'business_id', 'branch_id', 'start_date', 'end_date', 'status', 'job_title', 'is_primary', 'is_bookable',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_primary' => 'boolean',
            'is_bookable' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
