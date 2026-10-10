<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingAvailabilityRequest extends FormRequest
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
            'staff_profile_id' => ['required', 'integer'],
            'start_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ];
    }
}
