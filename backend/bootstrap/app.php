<?php

use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckPermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => CheckPermission::class,
            'feature'    => CheckFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Map framework exceptions onto the AIRR error envelope.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code'    => 'VALIDATION_ERROR',
                        'message' => 'The given data was invalid.',
                        'details' => $e->errors(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Not authenticated', 'details' => null],
                ], 401);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();
                $codes = [403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 429 => 'TOO_MANY_REQUESTS'];
                return response()->json([
                    'error' => [
                        'code'    => $codes[$status] ?? 'HTTP_ERROR',
                        'message' => $e->getMessage() ?: ($codes[$status] ?? 'Error'),
                        'details' => null,
                    ],
                ], $status);
            }
        });

        // An unauthenticated request without an "Accept: application/json" header (e.g. a
        // plain browser navigation / window.open() download link) makes Laravel's default
        // auth middleware try to redirect to a "login" route — which doesn't exist in this
        // API-only app, so it throws RouteNotFoundException("Route [login] not defined")
        // before the AuthenticationException handler above even runs, leaking a raw debug
        // trace full of vendor .php paths.
        $exceptions->render(function (\Symfony\Component\Routing\Exception\RouteNotFoundException $e, Request $request) {
            if ($request->is('api/*') && str_contains($e->getMessage(), 'login')) {
                return response()->json([
                    'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Not authenticated', 'details' => null],
                ], 401);
            }
        });

        // Final safety net — no api/* route should ever leak a raw debug trace for ANY
        // uncaught exception, whatever its type.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'SERVER_ERROR', 'message' => 'Something went wrong.', 'details' => null],
                ], 500);
            }
        });
    })->create();
