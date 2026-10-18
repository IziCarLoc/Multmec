<?php

use App\Http\Middleware\EnsureAccountIsUsable;
use App\Http\Middleware\LogSlowRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Telas e cliques do Livewire que passam de SLOW_REQUEST_MS vão para storage/logs/lento-*.log.
        $middleware->web(prepend: [LogSlowRequests::class], append: [EnsureAccountIsUsable::class]);
        // Hostinger: o HTTPS termina no proxy da hospedagem; sem isso o Laravel acha que a conexão é http.
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
