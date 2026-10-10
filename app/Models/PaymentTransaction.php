<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['amount_cents' => 'integer', 'evidence' => 'array', 'verified_at' => 'datetime']; }
    public function booking() { return $this->belongsTo(Booking::class); }
    public function refunds() { return $this->hasMany(RefundRequest::class); }
    public function reversal() { return $this->hasOne(self::class, 'reversal_of_id'); }
}
