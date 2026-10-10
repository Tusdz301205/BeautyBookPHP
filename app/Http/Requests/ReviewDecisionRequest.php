<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['APPROVE', 'REQUEST_INFO', 'REJECT'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
