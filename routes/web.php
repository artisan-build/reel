<?php

use App\Http\Controllers\ReplayPlayerController;
use App\Http\Controllers\ReplayPlayerUrlController;
use App\Http\Controllers\ReplaySessionController;
use App\Livewire\Applications\Create as CreateApplication;
use App\Livewire\Applications\Index as ApplicationIndex;
use App\Livewire\Applications\Show as ShowApplication;
use App\Livewire\Sessions\Index as SessionIndex;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('sessions', SessionIndex::class)->name('sessions.index');
    Route::livewire('applications/{application}/sessions', SessionIndex::class)
        ->name('applications.sessions.index');
    Route::get('applications/{application}/sessions/{recordingSession}', ReplaySessionController::class)
        ->name('sessions.show');
    Route::get('applications/{application}/sessions/{recordingSession}/player-url', ReplayPlayerUrlController::class)
        ->name('sessions.player-url');
    Route::get('applications/{application}/sessions/{recordingSession}/player', ReplayPlayerController::class)
        ->name('sessions.player');

    Route::middleware('admin')->prefix('applications')->group(function (): void {
        Route::livewire('/', ApplicationIndex::class)->name('admin.applications.index');
        Route::livewire('create', CreateApplication::class)->name('admin.applications.create');
        Route::livewire('{application}', ShowApplication::class)->name('admin.applications.show');
    });
});

require __DIR__.'/settings.php';
