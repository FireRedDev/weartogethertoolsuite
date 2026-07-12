<?php

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
        // FluentForms sendet keine CSRF-Token — der Webhook ist per Secret geschützt
        $middleware->validateCsrfTokens(except: ['webhooks/fluentforms/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Sicherheitsnetz: Statt einer kahlen 500-Seite immer eine erklärte
        // Fehlerseite mit technischen Details zeigen (auch bei APP_DEBUG=false).
        // Reguläre Fälle (404, Validierung, Auth, Wartungsmodus) bleiben unverändert.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson()) {
                return null;
            }
            if (
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || $e instanceof \Illuminate\Foundation\Http\Exceptions\MaintenanceModeException
            ) {
                return null;
            }

            return response()->view('errors.friendly', ['exception' => $e], 500);
        });
    })->create();
