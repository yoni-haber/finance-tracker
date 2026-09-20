<?php

declare(strict_types=1);

use App\Jobs\SweepStatementFileCleanupJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Laravel Cloud's load balancer, trust forwarded headers so the
        // app detects HTTPS, sets secure cookies, and sees the real client IP.
        $middleware->trustProxies(at: '*');
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new SweepStatementFileCleanupJob())->hourly()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
