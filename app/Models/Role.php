<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $primaryKey = 'name';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['name', 'label'];

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class, 'role_name', 'name');
    }
}
