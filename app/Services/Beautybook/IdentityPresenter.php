<?php

namespace App\Services\Beautybook;

use App\Models\StaffProfile;
use Illuminate\Http\Request;

class IdentityPresenter
{
    public function user(Request $request): array
    {
        $user = $request->user()->fresh();
        $assignment = app(ApiScope::class)->current($request);
        abort_unless($assignment, 403, 'Tài khoản không còn quyền truy cập.');
        $role = $assignment->role_name;
        $assignments = app(ApiScope::class)->assignments($request);
        $roles = $assignments->pluck('role_name')->unique()->values();
        $matrix = json_decode(file_get_contents(__DIR__.'/permissions.json'), true, 512, JSON_THROW_ON_ERROR);
        $staffBranches = $assignments->where('role_name', 'STAFF')->pluck('branch_id');
        $staff = StaffProfile::where('user_id', $user->id)->whereIn('branch_id', $staffBranches)->first();
        $profile = $user->customerProfile;

        return ['id' => (string) $user->id, 'fullName' => $user->name, 'email' => $user->email,
            'phone' => $profile?->phone, 'emailVerified' => (bool) $user->email_verified_at,
            'sessionType' => $role === 'CUSTOMER' ? 'customer' : ($role === 'PLATFORM_ADMIN' ? 'admin' : 'salon'),
            'workspace' => $role === 'CUSTOMER' ? 'CUSTOMER' : ($role === 'PLATFORM_ADMIN' ? 'PLATFORM' : 'SALON'),
            'businessId' => $assignment->business_id ? (string) $assignment->business_id : null, 'branchId' => $request->session()->get('beautybook_context_branch') ? (string) $request->session()->get('beautybook_context_branch') : ($assignment->branch_id ? (string) $assignment->branch_id : null),
            'roles' => $roles->all(), 'permissions' => $roles->flatMap(fn ($code) => $matrix[$code] ?? [])->unique()->values()->all(),
            'scopes' => $assignments->map(fn ($a) => ['code' => $a->role_name, 'businessId' => $a->business_id ? (string) $a->business_id : null, 'branchId' => $a->branch_id ? (string) $a->branch_id : null, 'expiresAt' => $a->expires_at?->toIso8601String()])->all(),
            'staffProfile' => $staff ? ['id' => (string) $staff->id, 'fullName' => $staff->full_name] : null,
            'dateOfBirth' => $profile?->date_of_birth, 'address' => $profile?->address,
            'avatarUrl' => $user->avatar_media_file_id ? url('/api/v1/users/me/avatar') : null,
        ];
    }

    public function session(Request $request): array
    {
        $token = bin2hex(random_bytes(32));
        $request->session()->put('beautybook_access_hash', hash('sha256', $token));
        $request->session()->put('beautybook_access_expires', time() + 900);

        return ['accessToken' => $token, 'user' => $this->user($request)];
    }
}
