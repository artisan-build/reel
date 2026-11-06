<?php

declare(strict_types=1);

use App\Http\Controllers\ProvisionApplicationController;
use App\Http\Controllers\ReplaceApplicationAllowedOriginsController;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureCurrentOwnerCredential;
use Illuminate\Support\Facades\Route;

Route::post('/bfc/applications', ProvisionApplicationController::class)
    ->middleware([
        'throttle:bfc-operator-write',
        EnsureCurrentOwnerCredential::class,
    ])
    ->name('bfc.applications.store');

Route::put('/bfc/applications/{application}/allowed-origins', ReplaceApplicationAllowedOriginsController::class)
    ->middleware([
        'throttle:bfc-operator-write',
        EnsureCurrentOwnerCredential::class,
    ])
    ->name('bfc.applications.allowed-origins.update');
