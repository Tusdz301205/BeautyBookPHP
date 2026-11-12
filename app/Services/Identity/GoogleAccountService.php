<?php

namespace App\Services\Identity;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Onboarding\BusinessOnboardingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Maps a verified Google identity to a local account (auth.service.ts loginWithGoogle). The subject is the durable key. */
class GoogleAccountService
{
    public function __construct(private RoleAssignmentService $roles, private BusinessOnboardingService $onboarding) {}

    /** @param array{sub:string,email:string,name:string,authoritative:bool} $identity */
    public function resolve(array $identity, string $accountType): User
    {
        return DB::transaction(function () use ($identity, $accountType) {
            $linked = DB::table('external_identities')->where('provider', 'GOOGLE')->where('subject', $identity['sub'])->lockForUpdate()->first();
            if ($linked) {
                $user = User::whereKey($linked->user_id)->lockForUpdate()->firstOrFail();
            } else {
                $user = User::where('email', $identity['email'])->lockForUpdate()->first();
                if ($user) {
                    // Google may claim an existing address only when it is the authority for that address.
                    abort_unless($identity['authoritative'], 409, 'Email này đã đăng ký. Hãy đăng nhập bằng mật khẩu.');
                    abort_if(DB::table('external_identities')->where('user_id', $user->id)->where('provider', 'GOOGLE')->exists(), 409, 'Tài khoản đã liên kết với một danh tính Google khác.');
                } else {
                    $user = $this->create($identity, $accountType);
                }
                DB::table('external_identities')->insert(['user_id' => $user->id, 'provider' => 'GOOGLE', 'subject' => $identity['sub'], 'email' => $identity['email'], 'created_at' => now(), 'updated_at' => now()]);
            }
            abort_unless($user->is_active, 403, 'Tài khoản đã bị khóa.');
            // Google sign-in must never reach platform administration.
            abort_if(DB::table('role_assignments')->where('user_id', $user->id)->where('role_name', RoleName::PlatformAdmin->value)->exists(), 403, 'Tài khoản quản trị phải đăng nhập bằng mật khẩu.');
            if (! $user->email_verified_at) $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        }, 3);
    }

    private function create(array $identity, string $accountType): User
    {
        $customer = $accountType !== 'BUSINESS_OWNER';
        // The random password is never disclosed; the account can set one later through password recovery.
        $user = User::create(['name' => $identity['name'], 'email' => $identity['email'], 'password' => Str::random(48), 'account_kind' => $customer ? AccountKind::Customer : AccountKind::Operational]);
        if ($customer) {
            CustomerProfile::create(['user_id' => $user->id]);
            $this->roles->grant($user, RoleName::Customer);
        } else {
            $this->onboarding->create($user, ['name' => 'Hồ sơ cơ sở của '.$user->name, 'slug' => 'draft-'.Str::uuid(), 'contact_email' => $user->email, 'contact_phone' => null]);
        }

        // Reload so database defaults such as is_active are present on the model.
        return $user->fresh();
    }
}
