<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'bookable' => ['sometimes', 'boolean'],
        ];
    }
}
