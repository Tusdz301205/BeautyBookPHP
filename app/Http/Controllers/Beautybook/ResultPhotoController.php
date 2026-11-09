<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\BranchServiceOffering;
use App\Models\MediaFile;
use App\Models\ResultPhoto;
use App\Services\Beautybook\ApiScope;
use App\Services\Media\ResultPhotoService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ResultPhotoController extends Controller
{
    private const VERSION = 'required|integer|between:0,4294967294';

    public function __construct(private ResultPhotoService $photos, private ApiScope $scope) {}

    public function upload(Request $request)
    {
        $data = $this->validate($request, [
            'businessId' => 'required|integer|min:1', 'branchId' => 'sometimes|integer|min:1',
            'purpose' => 'required|in:RESULT,CONSENT_PROOF', 'file' => 'required|file|max:10240',
        ]);
        $file = $this->photos->upload($request, $data['businessId'], $data['branchId'] ?? null, $data['purpose'], $request->file('file'));

        return response()->json(['id' => (string) $file->id, 'purpose' => $file->entity_type], 201);
    }

    public function create(Request $request)
    {
        $data = $this->validate($request, [
            'serviceId' => 'required|integer|min:1', 'mediaId' => 'required|integer|min:1',
            'bookingId' => 'sometimes|required|integer|min:1', 'bookingServiceId' => 'sometimes|required|integer|min:1',
            'consentSubjectReference' => 'sometimes|required|string|max:1000', 'proofMediaId' => 'sometimes|required|integer|min:1',
        ]);
        $photo = $this->photos->create($request, BranchServiceOffering::findOrFail($data['serviceId']), $data);

        return response()->json($this->data($photo), 201);
    }

    public function list(Request $request, BranchServiceOffering $offering): array
    {
        $this->photos->authorizeOwner($request, $offering->business_id);

        return $this->scope->paginate(ResultPhoto::where('branch_service_offering_id', $offering->id)->latest('id'),
            $request, fn (ResultPhoto $photo) => $this->data($photo));
    }

    public function uses(Request $request, MediaFile $file): array
    {
        $this->photos->authorizeAsset($request, $file);
        abort_unless($file->entity_type === ResultPhoto::MEDIA_TYPE, 404);
        $uses = $file->resultPhotos()->with('offering.service')->orderBy('id')->limit(ResultPhoto::MAX_USES + 1)->get();
        abort_if($uses->count() > ResultPhoto::MAX_USES, 409, 'Tệp vượt quá giới hạn lượt dùng.');

        return ['photos' => $uses->map(fn (ResultPhoto $photo) => [
            'id' => (string) $photo->id, 'serviceId' => (string) $photo->branch_service_offering_id,
            'serviceName' => $photo->offering?->service?->name,
            'version' => $photo->version, 'visibility' => $photo->published_at ? 'PUBLIC' : 'PRIVATE',
            'hasConsent' => (bool) $photo->consent_captured_at, 'revoked' => (bool) $photo->revoked_at,
        ])->all()];
    }

    public function capture(Request $request, ResultPhoto $photo): array
    {
        $data = $this->validate($request, [
            'expectedVersion' => self::VERSION,
            'capturedAt' => ['required', 'date', 'regex:/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,3})?(?:Z|[+-]\d\d:\d\d)\z/'],
            'source' => 'required|string|max:1000', 'version' => 'required|string|max:120',
        ]);

        return $this->data($this->photos->capture($request, $photo, $data));
    }

    public function publish(Request $request): array
    {
        $data = $this->validate($request, [
            'mediaId' => 'required|integer|min:1', 'photos' => 'required|array|list|min:1|max:'.ResultPhoto::MAX_USES,
            'photos.*' => 'required|array:id,expectedVersion', 'photos.*.id' => 'required|integer|min:1|distinct',
            'photos.*.expectedVersion' => self::VERSION,
        ]);
        $photos = $this->photos->publish($request, MediaFile::findOrFail($data['mediaId']), $data['photos']);

        return ['photos' => array_map(fn (ResultPhoto $photo) => $this->data($photo), $photos)];
    }

    public function withdraw(Request $request, ResultPhoto $photo): array
    {
        $data = $this->validate($request, ['expectedVersion' => self::VERSION]);

        return $this->data($this->photos->withdraw($request, $photo, $data['expectedVersion']));
    }

    public function publicList(Request $request, int $offering): array
    {
        BranchServiceOffering::publiclyVisible()->findOrFail($offering);
        $query = ResultPhoto::where('branch_service_offering_id', $offering)
            ->whereHas('media', fn ($media) => ResultPhoto::publicMedia($media))->latest('id');

        return $this->scope->paginate($query, $request, fn (ResultPhoto $photo) => [
            'id' => (string) $photo->id, 'url' => route('media.show', $photo->media_file_id, false),
        ]);
    }

    private function validate(Request $request, array $rules): array
    {
        abort_if(array_diff(array_keys($request->all()), array_keys($rules)), 422, 'Dữ liệu gửi có trường không được hỗ trợ.');

        return $request->validate($rules);
    }

    private function data(ResultPhoto $photo): array
    {
        return [
            'id' => (string) $photo->id, 'serviceId' => (string) $photo->branch_service_offering_id,
            'mediaId' => (string) $photo->media_file_id, 'version' => (int) $photo->version,
            'bookingId' => $photo->booking_id ? (string) $photo->booking_id : null,
            'bookingServiceId' => $photo->booking_service_id ? (string) $photo->booking_service_id : null,
            'consentSubjectId' => $photo->customer_profile_id ? (string) $photo->customer_profile_id : null,
            'consentSubjectReference' => $photo->subject_reference,
            'proofMediaId' => $photo->proof_media_id ? (string) $photo->proof_media_id : null,
            'capturedAt' => $photo->consent_captured_at ? CarbonImmutable::parse($photo->consent_captured_at, 'UTC')->toISOString() : null,
            'consentSource' => $photo->consent_source, 'consentVersion' => $photo->consent_version,
            'visibility' => $photo->published_at ? 'PUBLIC' : 'PRIVATE', 'revoked' => (bool) $photo->revoked_at,
        ];
    }
}
