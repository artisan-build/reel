<?php

use App\Http\Controllers\ApplicationEnrollmentController;
use Illuminate\Support\Facades\Route;

Route::post('applications/{application}/enrollment', [ApplicationEnrollmentController::class, 'store'])
    ->middleware('throttle:reel-enrollment')
    ->name('applications.enrollment.store');
