<?php

use App\Livewire\Applications\Create as CreateApplication;
use App\Livewire\Applications\Index as ApplicationIndex;
use App\Livewire\Applications\Show as ShowApplication;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::middleware('admin')->prefix('applications')->group(function (): void {
        Route::livewire('/', ApplicationIndex::class)->name('admin.applications.index');
        Route::livewire('create', CreateApplication::class)->name('admin.applications.create');
        Route::livewire('{application}', ShowApplication::class)->name('admin.applications.show');
    });
});

require __DIR__.'/settings.php';
