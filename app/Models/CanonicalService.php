<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanonicalService extends Model
{
    protected $fillable = [
        'code', 'slug', 'name', 'description', 'parent_id', 'replacement_canonical_id', 'synonyms', 'status',
    ];

    protected function casts(): array
    {
        return ['synonyms' => 'array'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_canonical_id');
    }

    public function businessServices(): HasMany
    {
        return $this->hasMany(BusinessService::class);
    }
}
