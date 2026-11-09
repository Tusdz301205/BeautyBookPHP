<?php

namespace App\Services\Media;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\MediaFile;
use App\Models\ResultPhoto;
use App\Models\RoleAssignment;
use App\Services\Beautybook\ApiScope;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ResultPhotoService
{
    public function __construct(private ApiScope $scope, private MediaManagementService $media) {}

    public function upload(Request $request, int $businessId, ?int $branchId, string $purpose, UploadedFile $file): MediaFile
    {
        $this->authorizeOwner($request, $businessId);
        if ($branchId !== null) {
            abort_unless(Branch::whereKey($branchId)->where('business_id', $businessId)->exists(), 422, 'Chi nhánh không thuộc doanh nghiệp.');
        }

        if ($purpose === ResultPhoto::PROOF_TYPE && $file->getMimeType() === 'application/pdf') {
            $mime = 'application/pdf';
            $extension = 'pdf';
            $size = $file->getSize();
            abort_unless($size > 0 && $size <= 10 * 1024 * 1024, 422, 'Tệp PDF tối đa 10 MB.');
            $bytes = file_get_contents($file->getRealPath());
            abort_unless(is_string($bytes) && preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)[\r\n]/', $bytes)
                && preg_match('/%%EOF\s*\z/', substr($bytes, -1024)), 422, 'Nội dung bằng chứng PDF không hợp lệ.');
        } else {
            [$mime, $extension, $size] = $this->media->validatePrivateImage($file);
        }

        $disk = Storage::disk('local');
        $path = 'result-photos/'.Str::uuid().'.'.$extension;
        abort_unless($disk->putFileAs(dirname($path), $file, basename($path)), 500, 'Không thể lưu tệp riêng tư.');

        try {
            return $this->write($request, $businessId, function (int $actor) use ($businessId, $branchId, $purpose, $path, $mime, $size) {
                return MediaFile::create([
                    'disk' => 'local', 'path' => $path, 'mime_type' => $mime, 'file_size' => $size,
                    'entity_type' => $purpose, 'entity_id' => $businessId, 'visibility' => 'PRIVATE',
                    'uploaded_by' => $actor, 'business_id' => $businessId, 'branch_id' => $branchId,
                ]);
            }, $branchId ? [$branchId] : []);
        } catch (Throwable $error) {
            // Keep the bytes if the transaction committed before its acknowledgement was lost.
            $stored = MediaFile::where('path', $path)->first();
            if ($stored) {
                return $stored;
            }
            $disk->delete($path);
            throw $error;
        }
    }

    public function create(Request $request, BranchServiceOffering $offering, array $data): ResultPhoto
    {
        $bookingMode = isset($data['bookingId']);
        abort_if($bookingMode ? isset($data['consentSubjectReference'])
            : ! isset($data['consentSubjectReference'], $data['proofMediaId']) || isset($data['bookingServiceId']),
            422, 'Hãy dùng booking hoặc người được xác định kèm bằng chứng riêng tư.');

        return $this->write($request, $offering->business_id, function (int $actor) use ($offering, $data, $bookingMode) {
            $offering = BranchServiceOffering::whereKey($offering->id)->lockForUpdate()->firstOrFail();
            $customerId = null;
            if ($bookingMode) {
                $booking = Booking::whereKey($data['bookingId'])->where('business_id', $offering->business_id)
                    ->where('branch_id', $offering->branch_id)->whereNotNull('customer_profile_id')->lockForUpdate()->first();
                abort_unless($booking && $booking->services()->where('branch_service_offering_id', $offering->id)
                    ->when(isset($data['bookingServiceId']), fn ($item) => $item->whereKey($data['bookingServiceId']))
                    ->lockForUpdate()->first(), 422, 'Booking phải có khách hàng và đúng lượt dịch vụ tại chi nhánh này.');
                $customerId = $booking->customer_profile_id;
            }

            $file = MediaFile::whereKey($data['mediaId'])->lockForUpdate()->first();
            $this->assertFile($file, ResultPhoto::MEDIA_TYPE, $offering);
            $this->assertProof($data['proofMediaId'] ?? null, $offering);
            $uses = $file->resultPhotos()->lockForUpdate()->get();
            abort_if($uses->count() >= ResultPhoto::MAX_USES || $uses->contains(fn ($use) => $use->published_at
                || $use->revoked_at || $use->branch_service_offering_id === $offering->id),
                409, 'Tệp đã có liên kết này, đã công khai, đã thu hồi hoặc đã đạt giới hạn lượt dùng.');

            $photo = ResultPhoto::create([
                'branch_service_offering_id' => $offering->id, 'media_file_id' => $file->id,
                'booking_id' => $data['bookingId'] ?? null, 'booking_service_id' => $data['bookingServiceId'] ?? null,
                'customer_profile_id' => $customerId, 'subject_reference' => $data['consentSubjectReference'] ?? null,
                'proof_media_id' => $data['proofMediaId'] ?? null,
            ]);
            $this->audit($actor, $offering->business_id, 'RESULT_PHOTO_CREATE', $photo);

            return $photo;
        }, [$offering->branch_id]);
    }

    public function capture(Request $request, ResultPhoto $photo, array $data): ResultPhoto
    {
        $offering = $photo->offering()->withTrashed()->firstOrFail();

        return $this->write($request, $offering->business_id, function (int $actor) use ($photo, $offering, $data) {
            $offering = BranchServiceOffering::whereKey($offering->id)->lockForUpdate()->firstOrFail();
            $file = $photo->media()->lockForUpdate()->firstOrFail();
            $photo = ResultPhoto::whereKey($photo->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($photo, $data['expectedVersion']);
            abort_if($photo->published_at || $photo->revoked_at, 409, 'Chỉ ghi đồng ý cho ảnh riêng tư chưa thu hồi.');
            $this->assertFile($file, ResultPhoto::MEDIA_TYPE, $offering);
            $this->assertProof($photo->proof_media_id, $offering);
            $capturedAt = CarbonImmutable::parse($data['capturedAt'])->utc();
            abort_if($capturedAt->isFuture(), 422, 'Thời điểm đồng ý không được ở tương lai.');

            $photo->update([
                'consent_captured_at' => $capturedAt->format('Y-m-d H:i:s.v'),
                'consent_source' => $data['source'], 'consent_version' => $data['version'],
                'consent_captured_by_id' => $actor, 'version' => $photo->version + 1,
            ]);
            $this->audit($actor, $offering->business_id, 'RESULT_CONSENT_CAPTURE', $photo);

            return $photo;
        }, [$offering->branch_id]);
    }

    public function publish(Request $request, MediaFile $file, array $revisions): array
    {
        $this->authorizeAsset($request, $file);
        abort_unless($file->entity_type === ResultPhoto::MEDIA_TYPE, 404);
        $requested = array_column($revisions, 'expectedVersion', 'id');
        $prepared = BranchServiceOffering::withTrashed()->whereIn('id', $file->resultPhotos()->select('branch_service_offering_id'))
            ->get(['id', 'branch_id', 'business_service_id'])->keyBy('id');

        return $this->write($request, $file->business_id, function (int $actor) use ($file, $requested, $prepared) {
            $file = MediaFile::whereKey($file->id)->lockForUpdate()->firstOrFail();
            $uses = $file->resultPhotos()->orderBy('id')->lockForUpdate()->get();
            abort_if($uses->isEmpty() || $uses->count() !== count($requested) || $uses->count() > ResultPhoto::MAX_USES
                || $uses->contains(fn ($use) => ! array_key_exists($use->id, $requested) || ! $prepared->has($use->branch_service_offering_id)),
                409, 'Phải xác nhận tất cả lượt dùng chung tệp. Hãy tải lại danh sách.');

            BusinessService::withTrashed()->whereKey($prepared->pluck('business_service_id')->unique()->all())
                ->orderBy('id')->lockForUpdate()->get();
            $offerings = BranchServiceOffering::publiclyVisible()->whereKey($prepared->keys()->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $now = now('UTC');
            foreach ($uses as $use) {
                $this->assertVersion($use, $requested[$use->id]);
                abort_if($use->revoked_at || ! $use->consent_captured_at || ! $use->consent_captured_by_id
                    || trim((string) $use->consent_source) === '' || trim((string) $use->consent_version) === ''
                    || CarbonImmutable::parse($use->consent_captured_at, 'UTC')->greaterThan($now),
                    409, 'Mọi lượt dùng cần có đồng ý còn hiệu lực.');
                $offering = $offerings->get($use->branch_service_offering_id);
                abort_unless($offering && $offering->branch_id === $prepared[$offering->id]->branch_id
                    && $offering->business_service_id === $prepared[$offering->id]->business_service_id,
                    409, 'Mọi dịch vụ liên kết phải đang hiển thị công khai.');
                $this->assertFile($file, ResultPhoto::MEDIA_TYPE, $offering);
                $this->assertProof($use->proof_media_id, $offering);
            }

            foreach ($uses->whereNull('published_at') as $use) {
                $use->update(['published_at' => $now->format('Y-m-d H:i:s.v'), 'published_by_id' => $actor,
                    'version' => $use->version + 1]);
                $this->audit($actor, $file->business_id, 'RESULT_PHOTO_PUBLISH', $use);
            }

            return $uses->all();
        }, $prepared->pluck('branch_id')->unique()->all());
    }

    public function withdraw(Request $request, ResultPhoto $photo, int $expectedVersion): ResultPhoto
    {
        $file = $photo->media()->firstOrFail();
        $this->authorizeAsset($request, $file);

        return $this->write($request, $file->business_id, function (int $actor) use ($file, $photo, $expectedVersion) {
            $file = MediaFile::whereKey($file->id)->lockForUpdate()->firstOrFail();
            $uses = $file->resultPhotos()->orderBy('id')->lockForUpdate()->get();
            $photo = $uses->firstWhere('id', $photo->id);
            abort_unless($photo, 404);
            $this->assertVersion($photo, $expectedVersion);
            if ($photo->revoked_at) {
                return $photo;
            }

            $file->resultPhotos()->update(['published_at' => null, 'published_by_id' => null,
                'version' => DB::raw('version + 1')]);
            $photo->refresh()->update(['revoked_at' => now('UTC')->format('Y-m-d H:i:s.v')]);
            $this->audit($actor, $file->business_id, 'RESULT_CONSENT_WITHDRAW', $photo);

            return $photo;
        });
    }

    public function authorizeAsset(Request $request, MediaFile $file): void
    {
        abort_unless(in_array($file->entity_type, [ResultPhoto::MEDIA_TYPE, ResultPhoto::PROOF_TYPE], true)
            && $file->business_id && $file->disk === 'local' && $file->visibility === 'PRIVATE', 404);
        $this->authorizeOwner($request, $file->business_id);
    }

    public function authorizeOwner(Request $request, int $businessId): void
    {
        $grant = $this->scope->forRoles($request, ['BUSINESS_OWNER'], $businessId);
        abort_unless($grant->branch_id === null && Business::whereKey($businessId)
            ->whereHas('ownerProfile', fn ($owner) => $owner->where('user_id', $request->user()->id))->exists(), 403);
    }

    private function write(Request $request, int $businessId, Closure $work, array $branchIds = []): mixed
    {
        $grant = $this->scope->forRoles($request, ['BUSINESS_OWNER'], $businessId);

        return DB::transaction(function () use ($grant, $businessId, $work, $branchIds) {
            // Branch then business follows booking writers. The business row serializes changes to shared files.
            $branches = Branch::whereKey($branchIds)->where('business_id', $businessId)->orderBy('id')->lockForUpdate()->get();
            abort_unless($branches->count() === count($branchIds), 422, 'Chi nhánh không còn trong phạm vi doanh nghiệp.');
            $business = Business::whereKey($businessId)->lockForUpdate()->firstOrFail();
            $liveGrant = RoleAssignment::whereKey($grant->id)->where('user_id', $grant->user_id)
                ->where('business_id', $businessId)->whereNull('branch_id')->where('role_name', 'BUSINESS_OWNER');
            $checkGrant = fn () => (clone $liveGrant)->where(fn ($query) => $query->whereNull('expires_at')
                ->orWhere('expires_at', '>', now('UTC')))->lockForUpdate()->first();
            abort_unless($checkGrant() && $business->ownerProfile()->where('user_id', $grant->user_id)->lockForUpdate()->first(), 403);

            $result = $work($grant->user_id);
            abort_unless($checkGrant(), 403);

            return $result;
        }, 3);
    }

    private function assertVersion(ResultPhoto $photo, int $expectedVersion): void
    {
        abort_unless($photo->version === $expectedVersion, 409, 'Hồ sơ đã thay đổi. Hãy tải lại trước khi tiếp tục.');
    }

    private function assertProof(?int $proofId, BranchServiceOffering $offering): void
    {
        if ($proofId !== null) {
            $this->assertFile(MediaFile::whereKey($proofId)->lockForUpdate()->first(), ResultPhoto::PROOF_TYPE, $offering);
        }
    }

    private function assertFile(?MediaFile $file, string $purpose, BranchServiceOffering $offering): void
    {
        abort_unless($file && $file->entity_type === $purpose && $file->disk === 'local' && $file->visibility === 'PRIVATE'
            && $file->business_id === $offering->business_id && ($file->branch_id === null || $file->branch_id === $offering->branch_id)
            && Storage::disk('local')->exists($file->path), 422,
            $purpose === ResultPhoto::PROOF_TYPE ? 'Bằng chứng riêng tư không hợp lệ hoặc sai phạm vi.' : 'Tệp ảnh kết quả riêng tư không hợp lệ hoặc sai phạm vi.');
    }

    private function audit(int $actor, int $businessId, string $action, ResultPhoto $photo): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $actor, 'business_id' => $businessId, 'action' => $action,
            'entity_type' => 'ResultPhoto', 'entity_id' => (string) $photo->id, 'created_at' => now('UTC'),
        ]);
    }
}
