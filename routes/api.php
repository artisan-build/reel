<?php

use App\Http\Controllers\RecordingChunkController;
use Illuminate\Support\Facades\Route;

Route::post('chunks', [RecordingChunkController::class, 'store'])
    ->name('recording-chunks.store');
