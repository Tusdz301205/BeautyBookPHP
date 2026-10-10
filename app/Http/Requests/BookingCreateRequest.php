<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingCreateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

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
            'staff_profile_id' => ['required', 'integer'],
            'start_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'source' => ['nullable', Rule::in(['WALK_IN', 'PHONE'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'violation_acknowledged' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_bool($value)) {
                        $fail('Xác nhận cảnh báo phải là giá trị boolean rõ ràng.');
                    }
                },
            ],
            'contact' => ['nullable', 'array'],
            'contact.full_name' => ['nullable', 'string', 'max:160'],
            'contact.phone' => ['nullable', 'string', 'max:32'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
