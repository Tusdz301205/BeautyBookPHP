<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class WaitlistEntry extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['offer_token_hash', 'offer_slot_key', 'active_join_key'];

    protected function casts(): array
    {
        return ['window_start' => 'datetime', 'window_end' => 'datetime', 'offered_start_at' => 'datetime', 'offer_expires_at' => 'datetime', 'accepted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function getWindowStartAttribute($value)
    {
        return $value ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    public function getWindowEndAttribute($value)
    {
        return $value ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    public function getOfferedStartAtAttribute($value)
    {
        return $value ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    public function getOfferExpiresAtAttribute($value)
    {
        return $value ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function offering()
    {
        return $this->belongsTo(BranchServiceOffering::class, 'branch_service_offering_id');
    }
}
