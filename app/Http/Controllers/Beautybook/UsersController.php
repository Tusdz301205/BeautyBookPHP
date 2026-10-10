<?php

namespace App\Http\Controllers\Beautybook;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\MediaFile;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Beautybook\ApiScope;
use App\Services\Beautybook\IdentityPresenter;
use App\Services\Platform\GovernanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function __construct(private ApiScope $scope, private IdentityPresenter $presenter) {}

    public function me(Request $request): array
    {
        $data = $this->presenter->user($request);
        $profile = DB::table('beautybook_user_profiles')->where('user_id', $request->user()->id)->first();
        if ($profile) $data = [...$data, 'phone' => $profile->phone, 'gender' => $profile->gender, 'dateOfBirth' => $profile->date_of_birth, 'address' => $profile->address];
        $assignment = $this->scope->current($request);
        $staff = StaffProfile::where('user_id', $request->user()->id)->where('branch_id', $assignment->branch_id)->first();
        if ($staff) $data['staffProfile'] = ['id' => (string) $staff->id, 'bio' => $staff->bio, 'experienceYears' => $staff->experience_years, 'position' => $staff->position, 'status' => $staff->status, 'branch' => ['id' => (string) $staff->branch_id, 'name' => $staff->branch->name], 'emergencyContactName' => $profile?->emergency_contact_name, 'emergencyContactPhone' => $profile?->emergency_contact_phone];
        $media = $request->user()->avatar_media_file_id ? MediaFile::find($request->user()->avatar_media_file_id) : null;
        $data['avatarMediaId'] = $media ? (string) $media->id : null;
        $data['avatarMedia'] = $media ? ['id' => (string) $media->id, 'url' => url('/api/v1/users/me/avatar')] : null;
        return $data;
    }

    public function updateMe(Request $request): array
    {
        $data = $request->validate(['fullName' => 'sometimes|required|string|min:2|max:100', 'phone' => ['sometimes', 'nullable', 'regex:/^(?:0\d{9}|\+84\d{9})$/'], 'gender' => 'sometimes|nullable|in:FEMALE,MALE,OTHER', 'dateOfBirth' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today', 'address' => 'sometimes|nullable|string|max:1000', 'staffBio' => 'sometimes|nullable|string|max:3000', 'experienceYears' => 'sometimes|nullable|integer|between:0,80', 'emergencyContactName' => 'sometimes|nullable|string|max:100', 'emergencyContactPhone' => ['sometimes', 'nullable', 'regex:/^(?:0\d{9}|\+84\d{9})$/'], 'avatarMediaId' => 'sometimes|nullable|integer']);
        DB::transaction(function () use ($request, $data) {
            $assignment = $this->scope->current($request);
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail(); abort_unless($user->is_active, 403);
            if (isset($data['fullName'])) $user->name = trim($data['fullName']);
            if (array_key_exists('avatarMediaId', $data)) {
                if ($data['avatarMediaId']) abort_unless(MediaFile::whereKey($data['avatarMediaId'])->where('uploaded_by', $user->id)->where('entity_id', $user->id)->whereIn('entity_type', ['USER_AVATAR', 'CUSTOMER_AVATAR'])->whereIn('visibility', ['PUBLIC', 'PRIVATE'])->exists(), 403);
                $user->forceFill(['avatar_media_file_id' => $data['avatarMediaId']]);
            }
            $user->save();
            $mapping = ['phone' => 'phone', 'gender' => 'gender', 'dateOfBirth' => 'date_of_birth', 'address' => 'address', 'emergencyContactName' => 'emergency_contact_name', 'emergencyContactPhone' => 'emergency_contact_phone'];
            $values = []; foreach ($mapping as $from => $to) if (array_key_exists($from, $data)) $values[$to] = $data[$from];
            if ($values) DB::table('beautybook_user_profiles')->updateOrInsert(['user_id' => $user->id], [...$values, 'updated_at' => now()]);
            if ($assignment->role_name === 'CUSTOMER') {
                $customer = $user->customerProfile; $fields = array_intersect_key($values, array_flip(['phone', 'date_of_birth', 'address']));
                if ($fields && $customer) $customer->forceFill($fields)->save();
            }
            if (in_array($assignment->role_name, ['STAFF', 'RECEPTIONIST'], true)) {
                $staff = StaffProfile::where('user_id', $user->id)->where('branch_id', $assignment->branch_id)->first();
                if ($staff) { $fields = []; if (array_key_exists('staffBio', $data)) $fields['bio'] = $data['staffBio']; if (array_key_exists('experienceYears', $data)) $fields['experience_years'] = $data['experienceYears']; $staff->fill($fields)->save(); }
            }
            DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => 'UPDATE', 'entity_type' => 'UserProfile', 'entity_id' => (string) $user->id, 'metadata' => json_encode(['fields' => array_keys($data)]), 'created_at' => now()]);
        }, 3);
        return $this->me($request);
    }

    public function avatar(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $media = MediaFile::whereKey($user->avatar_media_file_id)->where('uploaded_by', $user->id)->where('entity_id', $user->id)->whereIn('entity_type', ['USER_AVATAR', 'CUSTOMER_AVATAR'])->whereNull('business_id')->whereNull('branch_id')->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/avif'])->firstOrFail();
        abort_unless(\Illuminate\Support\Facades\Storage::disk($media->disk)->exists($media->path), 404);
        return \Illuminate\Support\Facades\Storage::disk($media->disk)->response($media->path, null, ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request): array
    {
        $assignment = $this->scope->current($request);
        abort_unless($assignment->role_name === 'PLATFORM_ADMIN', 403);
        $query = User::with('roleAssignments', 'customerProfile');
        if ($request->filled('search')) $query->where(function ($q) use ($request) {
            $pattern = '%'.$request->string('search').'%';
            $q->where('name', 'like', $pattern)->orWhere('email', 'like', $pattern)->orWhereHas('customerProfile', fn ($customer) => $customer->where('phone', 'like', $pattern))->orWhereExists(fn ($profile) => $profile->select(DB::raw(1))->from('beautybook_user_profiles')->whereColumn('user_id', 'users.id')->where('phone', 'like', $pattern));
        });
        if ($request->filled('role')) $query->whereHas('roleAssignments', fn ($q) => $q->where('role_name', $request->input('role')));
        if ($request->filled('status')) $query->where('is_active', $request->input('status') === 'ACTIVE');
        $result = $this->scope->paginate($query->latest(), $request, fn ($u) => $this->row($u));
        $summaryQuery = User::query();
        $result['meta'] = [...$result['pagination'], 'summary' => ['total' => (clone $summaryQuery)->count(), 'active' => (clone $summaryQuery)->where('is_active', true)->count(), 'suspended' => (clone $summaryQuery)->where('is_active', false)->count(), 'customers' => (clone $summaryQuery)->where('account_kind', 'CUSTOMER')->count(), 'teamMembers' => (clone $summaryQuery)->whereHas('roleAssignments', fn ($q) => $q->whereIn('role_name', ['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF']))->count()]];
        return $result;
    }

    public function show(Request $request, string $id): array
    {
        $this->scope->platform($request); $user = User::with('roleAssignments.business', 'roleAssignments.branch', 'customerProfile')->findOrFail($id);
        $data = $this->row($user);
        $data['userRoles'] = $user->roleAssignments->map(fn ($r) => ['id' => (string) $r->id, 'role' => ['code' => $r->role_name, 'name' => $r->role_name], 'business' => $r->business ? ['id' => (string) $r->business_id, 'name' => $r->business->name] : null, 'branch' => $r->branch ? ['id' => (string) $r->branch_id, 'name' => $r->branch->name] : null, 'expiresAt' => $r->expires_at?->toIso8601String()])->all();
        $data['sessions'] = DB::table('sessions')->where('user_id', $user->id)->get()->map(fn ($s) => ['id' => $s->id, 'workspace' => $user->account_kind->value === 'CUSTOMER' ? 'CUSTOMER' : ($user->roleAssignments->contains('role_name', 'PLATFORM_ADMIN') ? 'PLATFORM' : 'SALON'), 'createdAt' => date(DATE_ATOM, $s->last_activity), 'revokedAt' => null])->all();
        // Audit metadata may include request payloads or sensitive tokens; never expose it to the admin UI.
        $data['auditTrail'] = DB::table('audit_logs')->where('user_id', $user->id)->orderByDesc('id')->limit(100)->get()->map(fn ($a) => ['id' => (string) $a->id, 'action' => $a->action, 'createdAt' => $a->created_at])->all();
        $data['customerBookings'] = $user->customerProfile ? $this->bookings(Booking::where('customer_profile_id', $user->customerProfile->id)->latest()->limit(30)->get()) : [];
        $staff = StaffProfile::where('user_id', $user->id)->first();
        $data['staffProfile'] = $staff ? ['id' => (string) $staff->id, 'fullName' => $staff->full_name, 'futureBookings' => $this->bookings(Booking::whereHas('services', fn ($q) => $q->where('staff_profile_id', $staff->id))->where('appointment_start_at', '>=', now())->limit(30)->get())] : null;
        return $data;
    }
    public function suspend(Request $request, string $id, GovernanceService $service): array
    {
        $this->scope->platform($request);
        $data = $request->validate([
            'reason' => 'required|string|min:1|max:1000',
            'isActive' => 'required|boolean', 'expectedIsActive' => 'required|boolean',
            'acknowledgeImpact' => 'required|boolean',
        ]);
        $user = User::findOrFail($id);
        return $this->row($service->status($request, $user, $data['isActive'], trim($data['reason']), $data['expectedIsActive'], $data['acknowledgeImpact']));
    }

    public function suspensionImpact(Request $request, string $id, GovernanceService $service): array
    {
        $this->scope->platform($request);
        return $service->suspensionImpact(User::findOrFail($id));
    }
    public function grant(Request $request, string $id, GovernanceService $service): array
    {
        $data = $request->validate(['roleCode' => ['required', Rule::enum(RoleName::class)], 'businessId' => 'nullable|integer', 'branchId' => 'nullable|integer', 'expiresAt' => 'nullable|date|after:now']);
        $result = $service->grantRole($request, User::findOrFail($id), ['role_name' => $data['roleCode'], 'business_id' => $data['businessId'] ?? null, 'branch_id' => $data['branchId'] ?? null, 'expires_at' => $data['expiresAt'] ?? null]);
        return ['id' => (string) $result->id, 'roleCode' => $result->role_name];
    }
    public function revoke(Request $request, string $id, string $role, GovernanceService $service)
    {
        $assignment = RoleAssignment::where('user_id', $id)->where('role_name', $role)->where('business_id', $request->input('businessId'))->where('branch_id', $request->input('branchId'))->firstOrFail();
        $service->revokeRole($request, User::findOrFail($id), $assignment); return response()->noContent();
    }
    private function row(User $user): array {
        $profile = DB::table('beautybook_user_profiles')->where('user_id', $user->id)->first();
        return ['id' => (string) $user->id, 'name' => $user->name, 'fullName' => $user->name, 'email' => $user->email, 'phone' => $profile?->phone ?? $user->customerProfile?->phone, 'gender' => $profile?->gender, 'lastLoginAt' => DB::table('audit_logs')->where('user_id', $user->id)->where('entity_type', 'AccountSecurity')->where('action', 'LOGIN')->max('created_at'), 'roles' => $user->roleAssignments->pluck('role_name')->unique()->values()->all(), 'isActive' => (bool) $user->is_active, 'isEmailVerified' => (bool) $user->email_verified_at, 'accountKind' => $user->account_kind->value, 'joined' => $user->created_at, 'createdAt' => $user->created_at, 'bookings' => $user->customerProfile?->bookings()->count() ?? 0];
    }
    private function bookings($rows): array { return $rows->map(fn ($b) => ['id' => (string) $b->id, 'bookingCode' => $b->booking_code, 'status' => $b->status, 'appointmentDate' => $b->appointment_start_at, 'branch' => ['id' => (string) $b->branch_id, 'name' => $b->branch->name]])->all(); }
}
