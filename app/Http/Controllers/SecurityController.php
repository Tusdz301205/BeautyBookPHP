<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function index(Request $request): View
    {
        return view('security.index', [
            'assignments' => $request->user()->roleAssignments()->with(['business', 'branch'])->get(),
        ]);
    }

    public function revokeSessions(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        DB::table('sessions')->where('user_id', $request->user()->id)->delete();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'sessions-revoked');
    }
}
