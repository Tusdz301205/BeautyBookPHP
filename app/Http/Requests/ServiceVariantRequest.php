<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceVariantRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'consultation_required' => $this->boolean('consultation_required'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'price_type' => ['required', Rule::in(['FIXED', 'FROM', 'RANGE', 'QUOTE'])],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'max_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'consultation_required' => ['required', 'boolean'],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }
}
