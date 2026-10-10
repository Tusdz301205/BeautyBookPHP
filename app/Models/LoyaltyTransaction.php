<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rule_snapshot' => 'array', 'expires_at' => 'datetime'];
    }
}
