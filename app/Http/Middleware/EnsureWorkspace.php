<?php

namespace App\Http\Middleware;

use App\Services\Identity\SessionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspace
{
    public function __construct(private SessionContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $assignment = $this->context->current($request);

        abort_unless($assignment, 403);

        $request->attributes->set('workspace', $assignment);

        return $next($request);
    }
}
