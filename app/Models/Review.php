<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean', 'service_ratings' => 'array'];
    }

    public function booking() { return $this->belongsTo(Booking::class); }
    public function customerProfile() { return $this->belongsTo(CustomerProfile::class); }
}
