<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->isCustomer()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Bạn không có quyền truy cập.'], 403);
            }
            abort(403, 'Bạn không có quyền truy cập khu vực này.');
        }

        return $next($request);
    }
}
