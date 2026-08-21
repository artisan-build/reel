<?php

use App\Console\Commands\FinalizeRecordingSessions;
use App\Console\Commands\Fresh;
use App\Console\Commands\InstallFluxPro;
use App\Console\Commands\OptimizeTailwind;
use App\Console\Commands\ReconcileRecordingStorage;
use App\Console\Commands\RetainRecordingSessions;
use App\Console\Commands\RetryRecordingDeletions;
use App\Console\Commands\SweepRecordingOrphans;
use App\Http\Middleware\EnsureUserIsAdministrator;
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
    ->withCommands([
        Fresh::class,
        FinalizeRecordingSessions::class,
        InstallFluxPro::class,
        OptimizeTailwind::class,
        ReconcileRecordingStorage::class,
        RetainRecordingSessions::class,
        RetryRecordingDeletions::class,
        SweepRecordingOrphans::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdministrator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
