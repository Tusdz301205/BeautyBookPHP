<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StaffCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'offering_ids' => ['sometimes', 'array'],
            'offering_ids.*' => ['integer', 'distinct', 'exists:branch_service_offerings,id'],
        ];
    }
}
