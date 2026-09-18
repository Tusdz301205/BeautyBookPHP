<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isCustomer();
    }

    public function rules(): array
    {
        return [
            'booking_id' => ['required', 'exists:bookings,id'],
            'overall_rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'min:5', 'max:1000'],
            'is_anonymous' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'booking_id.required' => 'Mã lịch hẹn là bắt buộc.',
            'overall_rating.required' => 'Vui lòng chọn số sao đánh giá (1-5 sao).',
            'overall_rating.between' => 'Đánh giá phải từ 1 đến 5 sao.',
            'comment.required' => 'Vui lòng nhập nội dung đánh giá.',
            'comment.min' => 'Nội dung đánh giá phải có ít nhất 5 ký tự.',
            'comment.max' => 'Nội dung đánh giá không được vượt quá 1000 ký tự.',
        ];
    }
}
