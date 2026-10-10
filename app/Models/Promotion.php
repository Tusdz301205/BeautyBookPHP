<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['discount_value' => 'decimal:2', 'start_date' => 'datetime', 'end_date' => 'datetime', 'branch_ids' => 'array', 'service_ids' => 'array', 'combo_ids' => 'array', 'auto_apply' => 'boolean', 'stacking_allowed' => 'boolean', 'created_by_platform' => 'boolean'];
    }
}
