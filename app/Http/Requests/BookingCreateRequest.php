<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isCustomer();
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'service_id' => ['required', 'exists:services,id'],
            'staff_id' => ['nullable', 'exists:staff,id'],
            'appointment_date' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Vui lòng chọn chi nhánh.',
            'branch_id.exists' => 'Chi nhánh được chọn không tồn tại.',
            'service_id.required' => 'Vui lòng chọn dịch vụ.',
            'service_id.exists' => 'Dịch vụ được chọn không tồn tại.',
            'appointment_date.required' => 'Vui lòng chọn thời gian hẹn.',
            'appointment_date.after' => 'Thời gian hẹn phải ở tương lai.',
            'notes.max' => 'Ghi chú không được vượt quá 500 ký tự.',
        ];
    }
}
