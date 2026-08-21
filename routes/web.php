<?php

use App\Http\Controllers\AdminRecordingDeletionController;
use App\Http\Controllers\ApplicationUserErasureController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RecordingProtectionController;
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
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::livewire('sessions', SessionIndex::class)->name('sessions.index');
    Route::livewire('applications/{application}/sessions', SessionIndex::class)
        ->name('applications.sessions.index');
    Route::get('applications/{application}/sessions/{recordingSession}', ReplaySessionController::class)
        ->name('sessions.show');
    Route::get('applications/{application}/sessions/{recordingSession}/player-url', ReplayPlayerUrlController::class)
        ->name('sessions.player-url');
    Route::get('applications/{application}/sessions/{recordingSession}/player', ReplayPlayerController::class)
        ->name('sessions.player');
    Route::post('applications/{application}/sessions/{recordingSession}/protection', [RecordingProtectionController::class, 'store'])
        ->name('sessions.protection.store');
    Route::delete('applications/{application}/sessions/{recordingSession}/protection', [RecordingProtectionController::class, 'destroy'])
        ->name('sessions.protection.destroy');

    Route::middleware('admin')->prefix('applications')->group(function (): void {
        Route::livewire('/', ApplicationIndex::class)->name('admin.applications.index');
        Route::livewire('create', CreateApplication::class)->name('admin.applications.create');
        Route::livewire('{application}', ShowApplication::class)->name('admin.applications.show');
        Route::delete('{application}/sessions/{recordingSession}', AdminRecordingDeletionController::class)
            ->name('admin.sessions.destroy');
        Route::post('{application}/user-erasure', ApplicationUserErasureController::class)
            ->name('admin.application-users.destroy');
    });
});

require __DIR__.'/settings.php';
