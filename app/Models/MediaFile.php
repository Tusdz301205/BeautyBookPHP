<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFile extends Model
{
    public function resultPhotos(): HasMany
    {
        return $this->hasMany(ResultPhoto::class);
    }

    protected $fillable = [
        'disk', 'path', 'original_name', 'mime_type', 'file_size', 'uploaded_by', 'business_id', 'branch_id',
        'entity_type', 'entity_id', 'visibility',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
