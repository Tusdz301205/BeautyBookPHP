<?php

namespace App\Http\Requests;

use App\Models\StaffProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'public_visible' => $this->boolean('public_visible'),
            'is_bookable' => $this->boolean('is_bookable'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $staff = $this->route('staff');
        $branchId = (int) $this->input('branch_id');
        $employeeRule = Rule::unique('staff_profiles', 'employee_code')->where(fn ($query) => $query->where('branch_id', $branchId));

        if ($staff instanceof StaffProfile) {
            $employeeRule->ignore($staff->id);
        }

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'full_name' => ['required', 'string', 'max:160'],
            'position' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:4000'],
            'employee_code' => ['nullable', 'string', 'max:80', $employeeRule],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:80'],
            'public_visible' => ['required', 'boolean'],
            'is_bookable' => ['required', 'boolean'],
            'status' => ['required', Rule::in(['PROFILE_ONLY', 'INVITED', 'ACTIVE', 'LOCKED', 'INACTIVE'])],
            'hired_at' => ['nullable', 'date'],
        ];
    }
}
