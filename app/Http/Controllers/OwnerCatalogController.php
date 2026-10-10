<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogServiceRequest;
use App\Http\Requests\OfferingRequest;
use App\Http\Requests\ServiceVariantRequest;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\CanonicalService;
use App\Models\MediaFile;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Services\Catalog\CatalogManagementService;
use DomainException;
use Illuminate\Support\Facades\Gate;

class OwnerCatalogController extends Controller
{
    public function index(Business $business)
    {
        Gate::authorize('manage', $business);

        return view('owner.catalog.index', [
            'business' => $business->load('branches'),
            'categories' => ServiceCategory::query()->orderBy('name')->get(),
            'canonicals' => CanonicalService::query()->where('status', 'ACTIVE')->orderBy('name')->get(),
            'services' => BusinessService::query()
                ->where('business_id', $business->id)
                ->with(['category', 'canonicalService', 'offerings.branch', 'offerings.variants'])
                ->orderBy('name')
                ->get(),
            'mediaFiles' => MediaFile::query()
                ->where('business_id', $business->id)
                ->where('entity_type', 'SERVICE')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(CatalogServiceRequest $request, Business $business, CatalogManagementService $service)
    {
        Gate::authorize('manage', $business);

        return $this->run(fn () => $service->create($business, $request->validated()), 'Đã tạo dịch vụ và offering cho các chi nhánh đã chọn.');
    }

    public function update(CatalogServiceRequest $request, Business $business, BusinessService $service, CatalogManagementService $catalog)
    {
        $this->authorizeService($business, $service);

        return $this->run(fn () => $catalog->update($service, $request->validated()), 'Đã cập nhật dịch vụ.');
    }

    public function archive(Business $business, BusinessService $service, CatalogManagementService $catalog)
    {
        $this->authorizeService($business, $service);

        return $this->run(fn () => $catalog->archive($service), 'Đã lưu trữ dịch vụ và giữ nguyên lịch sử liên quan.');
    }

    public function updateOffering(OfferingRequest $request, Business $business, BranchServiceOffering $offering, CatalogManagementService $catalog)
    {
        $this->authorizeOffering($business, $offering);

        return $this->run(fn () => $catalog->updateOffering($offering, $request->validated()), 'Đã cập nhật offering tại chi nhánh.');
    }

    public function storeVariant(ServiceVariantRequest $request, Business $business, BranchServiceOffering $offering, CatalogManagementService $catalog)
    {
        $this->authorizeOffering($business, $offering);

        return $this->run(fn () => $catalog->createVariant($offering, $request->validated()), 'Đã thêm biến thể dịch vụ.');
    }

    public function archiveVariant(Business $business, BranchServiceOffering $offering, ServiceVariant $variant, CatalogManagementService $catalog)
    {
        $this->authorizeOffering($business, $offering);
        abort_unless($variant->branch_service_offering_id === $offering->id, 404);

        return $this->run(fn () => $catalog->archiveVariant($variant), 'Đã lưu trữ biến thể.');
    }

    private function authorizeService(Business $business, BusinessService $service): void
    {
        Gate::authorize('manage', $business);
        abort_unless($service->business_id === $business->id, 404);
    }

    private function authorizeOffering(Business $business, BranchServiceOffering $offering): void
    {
        Gate::authorize('manage', $business);
        abort_unless($offering->business_id === $business->id, 404);
    }

    private function run(callable $action, string $message)
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withErrors(['domain' => $e->getMessage()])->withInput();
        }

        return back()->with('status', $message);
    }
}
