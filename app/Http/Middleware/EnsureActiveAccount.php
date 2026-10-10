<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            if (! DB::table('users')->where('id', $user->id)->value('is_active')) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson() ? response()->json(['message' => 'Tài khoản đã bị tạm khóa.'], 403) : redirect()->route('login')->withErrors(['email' => 'Tài khoản đã bị tạm khóa.']);
            }
        }

        return $next($request);
    }
}
