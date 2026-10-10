<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_service_offering_id' => ['required', 'integer'],
            'service_variant_id' => ['nullable', 'integer'],
            'voucher_code' => ['nullable', 'string', 'max:64'],
            'loyalty_points' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
        ];
    }
}
