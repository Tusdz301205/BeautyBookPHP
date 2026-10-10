<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MediaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240'],
            'entity_type' => ['required', Rule::in(['BUSINESS', 'BRANCH', 'SERVICE', 'STAFF'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'visibility' => ['required', Rule::in(['PUBLIC', 'PRIVATE'])],
        ];
    }
}
