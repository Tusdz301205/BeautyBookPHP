<?php

namespace App\Services\Media;

use App\Models\MediaFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Where private media bytes live. New uploads go to config('media.private_disk') (local, or r2 for a private
 * Cloudflare R2 / S3-compatible bucket); every row records its own disk, so files uploaded before the switch
 * keep being read from local. Authorization stays in the callers: this class only moves bytes.
 */
final class PrivateMediaDisk
{
    public const DISKS = ['local', 'r2'];
    /** The largest object whose size and hash can be verified on read (the media-storage.ts limit). */
    public const MAX_VERIFIED_BYTES = 10 * 1024 * 1024;

    public static function name(): string
    {
        $disk = config('media.private_disk', 'local');

        return in_array($disk, self::DISKS, true) ? $disk : 'local';
    }

    public static function is(?string $disk): bool
    {
        return in_array($disk, self::DISKS, true);
    }

    /** Stores an uploaded file on the current private disk. A remote outage is a 503, never a silent local fallback. */
    public static function put(UploadedFile $file, string $path): string
    {
        $disk = self::name();
        try {
            $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'Kho lưu trữ media tạm thời không khả dụng.');
        }
        abort_unless($stored, 500, 'Không thể lưu tệp riêng tư.');

        return $disk;
    }

    private static function missing(\Throwable $e): bool
    {
        for ($previous = $e; $previous; $previous = $previous->getPrevious()) {
            if ($previous instanceof \Aws\S3\Exception\S3Exception && ($previous->getStatusCode() === 404 || in_array($previous->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true))) return true;
        }

        return false;
    }

    public static function exists(MediaFile $media): bool
    {
        try {
            return Storage::disk($media->disk)->exists($media->path);
        } catch (\Throwable $e) {
            if (self::missing($e)) return false;
            report($e);
            abort(503, 'Kho lưu trữ media tạm thời không khả dụng.');
        }
    }

    /** Bytes of a private file, or null when the object is gone. The hidden detail of an outage never reaches the client. */
    public static function read(MediaFile $media): ?string
    {
        try {
            $bytes = Storage::disk($media->disk)->get($media->path);
        } catch (\Throwable $e) {
            if (self::missing($e)) return null;
            report($e);
            abort(503, 'Kho lưu trữ media tạm thời không khả dụng.');
        }

        return is_string($bytes) ? $bytes : null;
    }

    /**
     * media-storage.ts get(): bytes of a private file that must be exactly what the database recorded. At most one byte
     * beyond the recorded size is read (a replaced oversized object cannot exhaust memory) and the length and SHA-256 must
     * both match, otherwise nothing is served. Null when the object is gone; a mismatch is a 404 too, never a different object.
     */
    public static function readVerified(MediaFile $media, int $sizeBytes, string $sha256): ?string
    {
        // A malformed reference (not a 64-char lowercase digest, size outside 1..10 MB, size not agreeing with the media row) serves nothing.
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $sha256) === 1 && $sizeBytes >= 1 && $sizeBytes <= self::MAX_VERIFIED_BYTES && (int) $media->file_size === $sizeBytes, 404, 'Không tìm thấy ảnh.');
        $stream = null;
        try {
            $stream = Storage::disk($media->disk)->readStream($media->path);
            $bytes = is_resource($stream) ? stream_get_contents($stream, $sizeBytes + 1) : null;
        } catch (\Throwable $e) {
            if (self::missing($e)) return null;
            report($e);
            abort(503, 'Kho lưu trữ media tạm thời không khả dụng.');
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        if (! is_string($bytes)) return null;
        if (strlen($bytes) !== $sizeBytes || ! hash_equals($sha256, hash('sha256', $bytes))) {
            report(new \RuntimeException('Private media object does not match its recorded size or hash (media_file '.$media->id.').'));
            abort(404, 'Không tìm thấy ảnh.');
        }

        return $bytes;
    }

    /**
     * The bytes of a private photo or document as recorded at upload: size and SHA-256 must both match when the row has a hash.
     * A row created before hashes were kept has none and is read on its size alone (it stays readable, never unchecked beyond that).
     */
    public static function readRecorded(MediaFile $media): ?string
    {
        $size = (int) $media->file_size;
        if (preg_match('/^[a-f0-9]{64}$/', (string) $media->sha256) === 1) return self::readVerified($media, $size, (string) $media->sha256);
        $bytes = self::read($media);

        return is_string($bytes) && strlen($bytes) === $size ? $bytes : null;
    }

    /** SHA-256 of an uploaded file, recorded with the media row. */
    public static function digest(\Illuminate\Http\UploadedFile $file): string
    {
        return (string) hash_file('sha256', $file->getRealPath());
    }

    public static function delete(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
