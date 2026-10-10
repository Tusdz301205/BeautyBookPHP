<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherRedemption extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'rule_snapshot' => 'array', 'applied_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
