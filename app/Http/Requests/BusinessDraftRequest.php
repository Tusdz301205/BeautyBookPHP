<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $business = $this->route('business');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('businesses', 'slug')->ignore($business?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'tax_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
