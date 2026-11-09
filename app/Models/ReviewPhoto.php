<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewPhoto extends Model
{
    public const TYPE = 'REVIEW_PHOTO';
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_PHOTOS = 5;
    public const MAX_PENDING = 20;

    public $timestamps = false;

    protected $fillable = ['booking_id', 'media_file_id', 'review_id', 'sort_order'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }

    public function data(bool $public = false): array
    {
        return [
            'id' => (string) $this->id,
            'mediaId' => (string) $this->media_file_id,
            'contentUrl' => route($public ? 'media.show' : 'beautybook.media.content', $this->media_file_id, false),
        ];
    }
}
