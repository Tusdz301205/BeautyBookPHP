<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isBusinessOwner();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'address' => ['required', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên chi nhánh là bắt buộc.',
            'address.required' => 'Địa chỉ chi nhánh là bắt buộc.',
            'status.required' => 'Trạng thái hoạt động là bắt buộc.',
        ];
    }
}
