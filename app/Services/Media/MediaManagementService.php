<?php

namespace App\Services\Media;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\MediaFile;
use App\Models\StaffProfile;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaManagementService
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
    ];

    public function upload(User $actor, Business $business, UploadedFile $file, string $entityType, int $entityId, string $visibility): MediaFile
    {
        [$branchId, $pivotTable, $pivotKey] = $this->scope($business, $entityType, $entityId);
        [$mime, $extension, $size] = $this->validateContent($file);
        $visibility = strtoupper($visibility);

        if (! in_array($visibility, ['PUBLIC', 'PRIVATE'], true)) {
            throw new DomainException('Visibility của media không hợp lệ.');
        }

        $disk = $visibility === 'PUBLIC' ? 'public' : 'local';
        $path = 'media/'.$business->id.'/'.Str::uuid().'.'.$extension;

        return DB::transaction(function () use ($actor, $business, $file, $entityType, $entityId, $visibility, $branchId, $pivotTable, $pivotKey, $mime, $size, $disk, $path) {
            $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

            if ($stored === false) {
                throw new DomainException('Không thể lưu file media.');
            }

            try {
                $media = MediaFile::query()->create([
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $mime,
                    'file_size' => $size,
                    'uploaded_by' => $actor->id,
                    'business_id' => $business->id,
                    'branch_id' => $branchId,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'visibility' => $visibility,
                ]);

                DB::table($pivotTable)->insert([
                    $pivotKey => $entityId,
                    'media_file_id' => $media->id,
                    'sort_order' => 0,
                    ...($pivotTable === 'business_images' || $pivotTable === 'branch_images' ? ['is_cover' => false] : []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $media;
            } catch (\Throwable $e) {
                Storage::disk($disk)->delete($path);
                throw $e;
            }
        });
    }

    public function canRead(?User $user, MediaFile $media): bool
    {
        if ($media->visibility === 'PUBLIC') {
            return true;
        }

        if (! $user || ! $media->business_id) {
            return false;
        }

        return $user->roleAssignments()
            ->where('role_name', 'BUSINESS_OWNER')
            ->where('business_id', $media->business_id)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
            ->exists();
    }

    private function scope(Business $business, string $entityType, int $entityId): array
    {
        return match ($entityType) {
            'BUSINESS' => $this->businessScope($business, $entityId),
            'BRANCH' => $this->branchScope($business, $entityId),
            'SERVICE' => $this->serviceScope($business, $entityId),
            'STAFF' => $this->staffScope($business, $entityId),
            default => throw new DomainException('Loại entity media không được hỗ trợ.'),
        };
    }

    private function businessScope(Business $business, int $entityId): array
    {
        if ($entityId !== $business->id) {
            throw new DomainException('Media business không thuộc doanh nghiệp hiện tại.');
        }

        return [null, 'business_images', 'business_id'];
    }

    private function branchScope(Business $business, int $entityId): array
    {
        $branch = Branch::query()->whereKey($entityId)->where('business_id', $business->id)->first();

        if (! $branch) {
            throw new DomainException('Media branch không thuộc doanh nghiệp hiện tại.');
        }

        return [$branch->id, 'branch_images', 'branch_id'];
    }

    private function serviceScope(Business $business, int $entityId): array
    {
        $offering = BranchServiceOffering::query()->whereKey($entityId)->where('business_id', $business->id)->first();

        if (! $offering) {
            throw new DomainException('Media service không thuộc doanh nghiệp hiện tại.');
        }

        return [$offering->branch_id, 'service_images', 'branch_service_offering_id'];
    }

    private function staffScope(Business $business, int $entityId): array
    {
        $profile = StaffProfile::query()->whereKey($entityId)->where('business_id', $business->id)->first();

        if (! $profile) {
            throw new DomainException('Media staff không thuộc doanh nghiệp hiện tại.');
        }

        return [$profile->branch_id, 'staff_images', 'staff_profile_id'];
    }

    public function validateContent(UploadedFile $file): array
    {
        $realPath = $file->getRealPath();

        if (! $realPath || ! is_file($realPath)) {
            throw new DomainException('Không thể đọc nội dung file media.');
        }

        $size = filesize($realPath);

        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw new DomainException('File media phải lớn hơn 0 byte và không vượt quá 10 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);

        if (! $mime || ! array_key_exists($mime, self::MIME_EXTENSIONS)) {
            throw new DomainException('Nội dung file không phải ảnh JPEG, PNG, WEBP hoặc AVIF hợp lệ.');
        }

        $originalExtension = mb_strtolower($file->getClientOriginalExtension());

        if (! in_array($originalExtension, self::MIME_EXTENSIONS[$mime], true)) {
            throw new DomainException('Phần mở rộng file không khớp với nội dung thực tế.');
        }

        if (! @getimagesize($realPath)) {
            throw new DomainException('Không thể xác minh kích thước và nội dung ảnh.');
        }

        return [$mime, self::MIME_EXTENSIONS[$mime][0], $size];
    }
}
