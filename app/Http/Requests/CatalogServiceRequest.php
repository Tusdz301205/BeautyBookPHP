<?php

namespace App\Http\Requests;

use App\Models\BusinessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $businessId = (int) $this->route('business')?->id;
        $service = $this->route('service');
        $slugRule = Rule::unique('business_services', 'slug')->where(fn ($query) => $query->where('business_id', $businessId));

        if ($service instanceof BusinessService) {
            $slugRule->ignore($service->id);
        }

        return [
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'canonical_service_id' => ['nullable', 'integer', 'exists:canonical_services,id'],
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'description' => ['nullable', 'string', 'max:4000'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:80'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'base_duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'status' => [$service ? 'required' : 'sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
            'branch_ids' => [$service ? 'sometimes' : 'required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct', 'exists:branches,id'],
        ];
    }
}
