<?php

namespace App\Http\Middleware;

use App\Enums\AccountKind;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountKind
{
    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $expected = AccountKind::tryFrom(strtoupper($kind));

        abort_unless($expected && $request->user()?->account_kind === $expected, 403);

        return $next($request);
    }
}
