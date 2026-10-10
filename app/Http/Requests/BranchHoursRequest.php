<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BranchHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.is_closed' => ['nullable', 'boolean'],
            'hours.*.open_time' => ['nullable', 'date_format:H:i'],
            'hours.*.close_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->input('hours', []) as $index => $row) {
                    $closed = filter_var($row['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    if ($closed) {
                        continue;
                    }

                    $open = $row['open_time'] ?? null;
                    $close = $row['close_time'] ?? null;

                    if (blank($open) || blank($close)) {
                        $validator->errors()->add("hours.$index.open_time", 'Ngày mở cửa phải có đủ giờ mở và giờ đóng.');
                        continue;
                    }

                    if ($open >= $close) {
                        $validator->errors()->add("hours.$index.close_time", 'Giờ đóng phải sau giờ mở.');
                    }
                }
            },
        ];
    }
}
