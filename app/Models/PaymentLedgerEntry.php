<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentLedgerEntry extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['amount_cents' => 'integer', 'created_at' => 'datetime']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Sổ tiền là dữ liệu chỉ ghi thêm.'));
        static::deleting(fn () => throw new \LogicException('Sổ tiền là dữ liệu chỉ ghi thêm.'));
    }
}
