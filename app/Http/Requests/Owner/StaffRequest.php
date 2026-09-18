<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isBusinessOwner();
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'position' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:active,inactive'],
            'services' => ['nullable', 'array'],
            'services.*' => ['exists:services,id'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Vui lòng chọn chi nhánh làm việc.',
            'name.required' => 'Họ và tên nhân viên là bắt buộc.',
        ];
    }
}
