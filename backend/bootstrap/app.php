<?php

use App\Http\Middleware\EnsureDisplayDevice;
use App\Http\Middleware\ResolveBranchContext;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'branch.context' => ResolveBranchContext::class,
            'device.type' => EnsureDisplayDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API exception rendering is kept in Laravel's default handler for now.
    })->create();
