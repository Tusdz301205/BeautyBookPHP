<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'role_code' => ['required', Rule::in(['RECEPTIONIST', 'STAFF'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'staff_profile_id' => ['nullable', 'integer', 'exists:staff_profiles,id'],
        ];
    }
}
