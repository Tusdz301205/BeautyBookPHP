<?php

namespace App\Http\Controllers\Beautybook;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Beautybook\IdentityPresenter;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\SessionContext;
use App\Services\Onboarding\BusinessOnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private IdentityPresenter $presenter, private SessionContext $context) {}

    public function login(LoginRequest $request)
    {
        $request->authenticate();
        try {
            $resolved = app(\App\Services\Beautybook\LoginContext::class)->resolve($request->user(), $request->only('workspace', 'businessId', 'branchId'));
        } catch (\Throwable $error) {
            Auth::logout();
            $request->session()->forget(['workspace_assignment_id', 'beautybook_context_branch', 'beautybook_access_hash', 'beautybook_access_expires']);
            throw $error;
        }
        $request->session()->regenerate();
        $request->session()->forget('beautybook_context_branch');
        if ($resolved['workspace'] === 'CUSTOMER') $request->session()->forget('workspace_assignment_id');
        else $this->context->select($request, $resolved['assignment']);
        if ($resolved['branchId']) $request->session()->put('beautybook_context_branch', $resolved['branchId']);
        $this->audit($request, 'LOGIN');
        return $this->sessionResponse($request);
    }

    public function register(Request $request, RoleAssignmentService $roles, BusinessOnboardingService $onboarding)
    {
        $data = $request->validate(['fullName' => 'required|string|min:2|max:100', 'email' => 'required|email|max:254|unique:users,email', 'password' => ['required', 'max:128', Password::min(8)->mixedCase()->numbers()], 'phone' => ['nullable', 'regex:/^(?:0\d{9}|\+84\d{9})$/'], 'accountType' => 'required|in:CUSTOMER,BUSINESS_OWNER']);
        $user = DB::transaction(function () use ($data, $roles, $onboarding) {
            $user = User::create(['name' => trim($data['fullName']), 'email' => strtolower($data['email']), 'password' => $data['password'], 'account_kind' => $data['accountType'] === 'CUSTOMER' ? AccountKind::Customer : AccountKind::Operational]);
            if ($data['accountType'] === 'CUSTOMER') {
                CustomerProfile::create(['user_id' => $user->id])->forceFill(['phone' => $data['phone'] ?? null])->save();
                $roles->grant($user, RoleName::Customer);
            } else {
                // A real private draft gives the pending owner a valid tenant assignment.
                $onboarding->create($user, ['name' => 'Hồ sơ cơ sở của '.$user->name, 'slug' => 'draft-'.Str::uuid(), 'contact_email' => $user->email, 'contact_phone' => $data['phone'] ?? null]);
            }
            return $user;
        }, 3);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('beautybook_context_branch');
        $this->context->selectInitial($request);
        $this->sendToken($user, 'VERIFY_EMAIL', 1440, '/beautybook/verify-email');
        $this->audit($request, 'REGISTER');
        return $this->sessionResponse($request, 201);
    }

    public function refresh(Request $request)
    {
        abort_unless($request->user(), 401, 'Vui lòng đăng nhập.');
        abort_unless($request->user()->is_active, 403);
        return $this->sessionResponse($request);
    }

    public function logout(Request $request)
    {
        $this->audit($request, 'LOGOUT');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['success' => true])->header('X-CSRF-TOKEN', csrf_token());
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        if ($user = User::where('email', strtolower($data['email']))->where('is_active', true)->first()) {
            $this->sendToken($user, 'RESET_PASSWORD', 30, '/beautybook/reset-password');
        }
        return response()->json(['message' => 'Nếu email tồn tại, hướng dẫn đã được gửi.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:128', 'newPassword' => ['required', 'max:128', Password::min(8)->mixedCase()->numbers()]]);
        DB::transaction(function () use ($data) {
            $token = $this->consume($data['token'], 'RESET_PASSWORD');
            User::findOrFail($token->user_id)->forceFill(['password' => Hash::make($data['newPassword']), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $token->user_id)->delete();
        }, 3);
        if ($request->user()) { Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken(); }
        return response()->json(['requiresLogin' => true])->header('X-CSRF-TOKEN', csrf_token());
    }

    public function verifyEmail(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:128']);
        DB::transaction(function () use ($data) {
            $token = $this->consume($data['token'], 'VERIFY_EMAIL');
            User::findOrFail($token->user_id)->markEmailAsVerified();
        }, 3);
        return response()->json(['success' => true]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['currentPassword' => 'required|current_password', 'newPassword' => ['required', 'max:128', Password::min(8)->mixedCase()->numbers()]]);
        DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail()->forceFill(['password' => Hash::make($data['newPassword']), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $request->user()->id)->delete();
            $this->audit($request, 'PASSWORD_CHANGED');
        }, 3);
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return response()->json(['requiresLogin' => true])->header('X-CSRF-TOKEN', csrf_token());
    }

    public function sessions(Request $request)
    {
        return response()->json(DB::table('sessions')->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get()->map(fn ($row) => ['id' => $row->id, 'current' => hash_equals($request->session()->getId(), $row->id), 'userAgent' => $row->user_agent, 'ipAddress' => $row->ip_address, 'lastActiveAt' => date(DATE_ATOM, $row->last_activity)]));
    }

    public function revokeSession(Request $request, string $id)
    {
        abort_if(hash_equals($request->session()->getId(), $id), 422, 'Không thể thu hồi thiết bị hiện tại tại đây.');
        $count = DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $id)->delete();
        abort_unless($count, 404);
        $this->audit($request, 'SESSION_REVOKED');
        return response()->json(['revokedCount' => $count]);
    }

    public function revokeOthers(Request $request)
    {
        $count = DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        $this->audit($request, 'OTHER_SESSIONS_REVOKED');
        return response()->json(['revokedCount' => $count]);
    }

    public function history(Request $request)
    {
        $events = DB::table('audit_logs')->where('user_id', $request->user()->id)->where('entity_type', 'AccountSecurity')->orderByDesc('created_at')->limit(100)->get()->map(fn ($row) => ['id' => (string) $row->id, 'type' => $row->action, 'action' => $row->action, 'createdAt' => $row->created_at, 'metadata' => json_decode($row->metadata ?? '{}', true)]);
        return response()->json(['events' => $events, 'sessions' => $this->sessions($request)->getData(true)]);
    }

    private function sessionResponse(Request $request, int $status = 200)
    {
        return response()->json($this->presenter->session($request), $status)->header('X-CSRF-TOKEN', csrf_token())->header('Cache-Control', 'no-store');
    }

    private function sendToken(User $user, string $purpose, int $minutes, string $path): void
    {
        $plain = bin2hex(random_bytes(32));
        DB::table('beautybook_auth_tokens')->insert(['user_id' => $user->id, 'purpose' => $purpose, 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addMinutes($minutes), 'created_at' => now(), 'updated_at' => now()]);
        $url = url($path).'?token='.urlencode($plain);
        Mail::raw('BeautyBook: '.$url."\nLiên kết hết hạn sau {$minutes} phút.", fn ($mail) => $mail->to($user->email)->subject($purpose === 'VERIFY_EMAIL' ? 'Xác minh email BeautyBook' : 'Đặt lại mật khẩu BeautyBook'));
    }

    private function consume(string $plain, string $purpose): object
    {
        $token = DB::table('beautybook_auth_tokens')->where('token_hash', hash('sha256', $plain))->where('purpose', $purpose)->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
        abort_unless($token, 422, 'Liên kết không hợp lệ, đã dùng hoặc đã hết hạn.');
        DB::table('beautybook_auth_tokens')->where('id', $token->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
        return $token;
    }

    private function audit(Request $request, string $action): void
    {
        if (! $request->user()) return;
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => $action, 'entity_type' => 'AccountSecurity', 'entity_id' => (string) $request->user()->id, 'metadata' => json_encode(['ipAddress' => $request->ip(), 'userAgent' => $request->userAgent()]), 'created_at' => now()]);
    }
}
