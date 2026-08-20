<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Http\Middleware\RememberCapturePolicy;
use ArtisanBuild\ReelClient\ReelClientServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;

it('registers the Reel client package', function (): void {
    $this->assertInstanceOf(
        ReelClientServiceProvider::class,
        app()->getProvider(ReelClientServiceProvider::class),
    );
    $this->assertNotNull(config('reel'));
});

it('keeps the shared protocol package as an intentional runtime ingest dependency', function (): void {
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require'])->toHaveKey('artisan-build/reel-client', '*')
        ->and($composer['require-dev'])->not->toHaveKey('artisan-build/reel-client')
        ->and($composer['repositories'])->toContain([
            'type' => 'path',
            'url' => 'packages/reel-client',
            'options' => ['symlink' => true],
        ]);
});

it('keeps monitored-application routes and middleware off the Reel server', function (): void {
    $kernel = resolve(HttpKernelContract::class);

    expect(Route::has('reel.session-grants.store'))->toBeFalse()
        ->and(Route::has('reel.assets.rrweb'))->toBeFalse()
        ->and(Route::has('reel.assets.recorder'))->toBeFalse()
        ->and($kernel)->toBeInstanceOf(HttpKernel::class)
        ->and($kernel->hasMiddleware(RememberCapturePolicy::class))->toBeFalse();
});
