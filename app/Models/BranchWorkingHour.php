<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchWorkingHour extends Model
{
    public $timestamps = false;

    protected $fillable = ['branch_id', 'day_of_week', 'open_time', 'close_time', 'is_closed'];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
