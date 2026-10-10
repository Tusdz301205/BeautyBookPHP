<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyAccount extends Model
{
    protected $guarded = ['id'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function transactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}
