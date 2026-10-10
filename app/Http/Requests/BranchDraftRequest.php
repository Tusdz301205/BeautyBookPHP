<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $business = $this->route('business');
        $branch = $this->route('branch');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('branches', 'slug')->where('business_id', $business?->id)->ignore($branch?->id)],
            'public_name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'address_line' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['required', Rule::in(['Asia/Ho_Chi_Minh'])],
        ];
    }
}
