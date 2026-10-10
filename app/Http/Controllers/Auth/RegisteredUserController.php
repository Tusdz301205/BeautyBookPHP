<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request, RoleAssignmentService $assignments): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $assignments) {
            $user = User::create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'account_kind' => AccountKind::Customer,
            ]);

            CustomerProfile::query()->create(['user_id' => $user->id]);
            $assignments->grant($user, RoleName::Customer);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
