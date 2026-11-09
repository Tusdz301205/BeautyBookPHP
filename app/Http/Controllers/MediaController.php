<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\ReviewPhoto;
use App\Models\ResultPhoto;
use App\Services\Media\MediaManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function show(Request $request, MediaFile $media, MediaManagementService $service)
    {
        abort_if($media->entity_type === ResultPhoto::PROOF_TYPE, 404);
        if (in_array($media->entity_type, [ReviewPhoto::TYPE, ResultPhoto::MEDIA_TYPE], true)) {
            return $service->privateImageContent($media, function () use ($media, $service) {
                abort_unless($service->canRead(null, $media), 404, '', ['Cache-Control' => 'private, no-store']);
            });
        }

        abort_unless($service->canRead($request->user(), $media), 403);
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        return Storage::disk($media->disk)->response(
            $media->path,
            $media->original_name ?: basename($media->path),
            [
                'Content-Type' => $media->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => $media->visibility === 'PUBLIC' ? 'public, max-age=3600' : 'private, no-store',
            ],
        );
    }
}
