<?php

namespace App\Http\Middleware;

use App\Services\Beautybook\ApiScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BeautybookSession
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 401, 'Vui lòng đăng nhập.');
        $token = $request->bearerToken();
        $hash = $request->session()->get('beautybook_access_hash');
        abort_unless($token && $hash && hash_equals($hash, hash('sha256', $token)) && $request->session()->get('beautybook_access_expires', 0) > time(), 401, 'Phiên đã hết hạn.');
        app(ApiScope::class)->current($request);
        return $next($request);
    }
}
