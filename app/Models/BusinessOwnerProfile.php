<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessOwnerProfile extends Model
{
    protected $fillable = ['user_id', 'company_name', 'tax_code'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'owner_profile_id');
    }
}
