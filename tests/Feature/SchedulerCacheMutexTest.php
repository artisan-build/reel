<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;

it('refuses to boot when a missing default cache store would silently acquire every mutex', function (): void {
    config()->set('cache.default', null);

    $mutex = resolve(CacheEventMutex::class);
    $event = new Event($mutex, 'php artisan reel:retain-sessions');

    expect($mutex->create($event))->toBeTrue()
        ->and($mutex->create($event))->toBeTrue();

    expect(function (): void {
        (new AppServiceProvider(app()))->boot();
    })
        ->toThrow(RuntimeException::class, 'CACHE_STORE must name a configured cache store.');
});

it('refuses to boot when the default cache store is not defined', function (): void {
    config()->set('cache.default', 'does-not-exist');

    $mutex = resolve(CacheEventMutex::class);
    $event = new Event($mutex, 'php artisan reel:retain-sessions');

    expect(fn (): bool => $mutex->create($event))
        ->toThrow(InvalidArgumentException::class, 'Cache store [does-not-exist] is not defined.');

    expect(function (): void {
        (new AppServiceProvider(app()))->boot();
    })
        ->toThrow(RuntimeException::class, 'CACHE_STORE must name a configured cache store.');
});
