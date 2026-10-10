<?php

namespace App\Http\Controllers;

use App\Http\Requests\MediaUploadRequest;
use App\Models\Business;
use App\Services\Media\MediaManagementService;
use DomainException;
use Illuminate\Support\Facades\Gate;

class OwnerMediaController extends Controller
{
    public function store(MediaUploadRequest $request, Business $business, MediaManagementService $service)
    {
        Gate::authorize('manage', $business);

        try {
            $service->upload(
                $request->user(),
                $business,
                $request->file('file'),
                $request->string('entity_type')->toString(),
                $request->integer('entity_id'),
                $request->string('visibility')->toString(),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['media' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Đã tải media lên đúng scope.');
    }
}
