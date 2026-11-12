<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SepayWebhookEvent extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['transaction_at' => \App\Casts\UtcDateTime::class, 'received_at' => \App\Casts\UtcDateTime::class, 'reviewed_at' => \App\Casts\UtcDateTime::class];
    }
}
