<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'slug'];

    public function districts()
    {
        return $this->hasMany(District::class);
    }
}
