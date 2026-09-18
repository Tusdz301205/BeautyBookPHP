<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

class BusinessUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isBusinessOwner();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên doanh nghiệp là bắt buộc.',
            'logo.image' => 'File tải lên phải là hình ảnh hợp lệ.',
            'logo.mimes' => 'Hình ảnh phải có định dạng: jpeg, png, jpg, webp.',
            'logo.max' => 'Kích thước hình ảnh không được vượt quá 2MB.',
        ];
    }
}
