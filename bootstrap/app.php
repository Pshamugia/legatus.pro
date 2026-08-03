<?php

use App\Http\Middleware\RequireActiveSubscription;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['subscribed' => RequireActiveSubscription::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $exception, \Illuminate\Http\Request $request) {
            if (! $request->isMethod('post') || ! $request->is('logout')) {
                return null;
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('landing');
        });
    })->create();
