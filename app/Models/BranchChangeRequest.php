<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;

class BranchChangeRequest extends Model
{
    protected $guarded = ['id', 'pending_branch_id'];

    protected function casts(): array
    {
        return ['base_snapshot' => 'array', 'proposed_snapshot' => 'array', 'version' => 'integer', 'reviewed_at' => UtcDateTime::class];
    }
}
