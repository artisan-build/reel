<?php

use App\Http\Controllers\ApplicationEnrollmentController;
use App\Http\Controllers\RecordingChunkController;
use Illuminate\Support\Facades\Route;

Route::post('applications/{application}/enrollment', [ApplicationEnrollmentController::class, 'store'])
    ->middleware('throttle:reel-enrollment')
    ->name('applications.enrollment.store');

Route::post('chunks', [RecordingChunkController::class, 'store'])
    ->name('recording-chunks.store');
