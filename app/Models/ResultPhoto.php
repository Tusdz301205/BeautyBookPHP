<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultPhoto extends Model
{
    public const MEDIA_TYPE = 'RESULT';
    public const PROOF_TYPE = 'CONSENT_PROOF';
    public const MAX_USES = 100;

    protected $guarded = ['id'];

    protected $casts = ['version' => 'integer'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(BranchServiceOffering::class, 'branch_service_offering_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }

    public function proof(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'proof_media_id');
    }

    public static function publicMedia(Builder $query): Builder
    {
        return $query->where('entity_type', self::MEDIA_TYPE)->where('disk', 'local')->where('visibility', 'PRIVATE')
            ->whereHas('resultPhotos')
            ->whereDoesntHave('resultPhotos', fn (Builder $uses) => $uses->where(function (Builder $invalid) {
                $invalid->whereNull('published_at')->orWhere('published_at', '>', now('UTC')->format('Y-m-d H:i:s.v'))
                    ->orWhereNotNull('revoked_at')->orWhereNull('published_by_id')
                    ->orWhereNull('consent_captured_at')->orWhereColumn('consent_captured_at', '>', 'published_at')
                    ->orWhereNull('consent_captured_by_id')
                    ->orWhereNull('consent_source')->orWhere('consent_source', '')
                    ->orWhereNull('consent_version')->orWhere('consent_version', '')
                    ->orWhereDoesntHave('offering', fn (Builder $offering) => $offering->publiclyVisible())
                    ->orWhere(fn (Builder $proof) => $proof->whereNotNull('proof_media_id')
                        ->whereDoesntHave('proof', fn (Builder $media) => $media->where('entity_type', self::PROOF_TYPE)
                            ->where('disk', 'local')->where('visibility', 'PRIVATE')));
            }));
    }
}
