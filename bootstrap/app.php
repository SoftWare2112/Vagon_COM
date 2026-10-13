<?php

use App\Http\Middleware\PasswordCambiada;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();                 // Sanctum SPA: sesión + CSRF en /api
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['password.cambiada' => PasswordCambiada::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Nunca filtrar trazas al cliente; Laravel ya responde JSON en /api
        $exceptions->dontFlash(['password', 'password_confirmation', 'password_actual']);
    })->create();
