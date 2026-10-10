<?php

use App\Http\Middleware\EnsureAccountKind;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnsureActiveAccount::class]);
        $middleware->alias([
            'account' => EnsureAccountKind::class,
            'workspace' => EnsureWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\App\Exceptions\BookingConflictException $exception, Request $request) {
            if ($request->is('api/v1/*')) return response()->json(['message' => $exception->getMessage()], 409);
        });
        $exceptions->render(function (\DomainException $exception, Request $request) {
            if ($request->is('api/v1/*')) return response()->json(['message' => $exception->getMessage()], 422);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
