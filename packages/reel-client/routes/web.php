<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Http\Controllers\AssetController;
use ArtisanBuild\ReelClient\Http\Controllers\SessionGrantController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::post('/reel/session-grants', SessionGrantController::class)
        ->middleware('throttle:reel-grants')
        ->name('reel.session-grants.store');

    Route::get('/reel/assets/rrweb-2.1.1.js', [AssetController::class, 'rrweb'])
        ->name('reel.assets.rrweb');

    Route::get('/reel/assets/reel-recorder-0.1.0.js', [AssetController::class, 'recorder'])
        ->name('reel.assets.recorder');
});
