<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Provider webhooks authenticate by signature, not by session or CSRF token.
        $middleware->preventRequestForgery(except: ['webhooks/*']);

        $middleware->alias([
            'feature' => EnsureFeatureEnabled::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureAccountActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A rejected message or review is redirected back with its input flashed to the session; the
        // body/comment must not go there, or an over-long one holding a phone number would be kept
        // unmasked (R134/R136).
        $exceptions->dontFlash(['body', 'comment']);

        // R187(a): a conflict the app raises itself (abort(409, ...)) on a form submit — a double click,
        // a stale tab, a step already locked — is told to the user on the page they are on, never as a
        // bare error page. Only a deliberate 409 HttpException is handled here: every other status and
        // every other exception still reaches the normal handler (and the log). The Inertia
        // asset-version 409 is a middleware response, not an exception; the client reloads on it.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 409 || $request->isMethodSafe() || $request->expectsJson()) {
                return null;
            }

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'That could not be done because the page was out of date. Please check it and try again.',
            ]);

            return redirect()->back(fallback: route('home'));
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
