<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchStateTransition extends Model
{
    protected $fillable = [
        'branch_id', 'actor_id', 'action', 'from_status', 'to_status',
        'from_review_status', 'to_review_status', 'from_operational_status',
        'to_operational_status', 'reason', 'version',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
