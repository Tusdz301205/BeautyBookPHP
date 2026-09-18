<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isBusinessOwner();
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'category_id' => ['nullable', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'integer', 'min:0'],
            'discount_price' => ['nullable', 'integer', 'min:0', 'lt:price'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'status' => ['required', 'in:active,inactive'],
            'is_bookable' => ['boolean'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Vui lòng chọn chi nhánh cung cấp dịch vụ.',
            'name.required' => 'Tên dịch vụ là bắt buộc.',
            'price.required' => 'Giá dịch vụ là bắt buộc.',
            'price.min' => 'Giá dịch vụ không được âm.',
            'discount_price.lt' => 'Giá khuyến mãi phải nhỏ hơn giá gốc.',
            'duration_minutes.required' => 'Thời gian thực hiện dịch vụ là bắt buộc.',
            'duration_minutes.min' => 'Thời gian tối thiểu là 5 phút.',
        ];
    }
}
