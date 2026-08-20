<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Http\Middleware\CorrelateReelRequest;
use ArtisanBuild\ReelClient\Http\Middleware\RedactReelHeaders;
use ArtisanBuild\ReelClient\Http\Middleware\RememberCapturePolicy;
use ArtisanBuild\ReelClient\ReelClientServiceProvider;
use ArtisanBuild\ReelClient\Tests\TestCase;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

uses(TestCase::class);

it('boots standalone', function (): void {
    expect($this->app->getProvider(ReelClientServiceProvider::class))
        ->toBeInstanceOf(ReelClientServiceProvider::class)
        ->and(config('reel'))->not->toBeNull();
});

it('registers host routes middleware and grant limiting in explicit host mode', function (): void {
    $kernel = $this->app->make(HttpKernelContract::class);
    $globalMiddleware = $kernel instanceof HttpKernel ? $kernel->getGlobalMiddleware() : [];

    expect(Route::has('reel.session-grants.store'))->toBeTrue()
        ->and(Route::has('reel.assets.rrweb'))->toBeTrue()
        ->and(Route::has('reel.assets.recorder'))->toBeTrue()
        ->and($kernel)->toBeInstanceOf(HttpKernel::class)
        ->and($kernel->hasMiddleware(RedactReelHeaders::class))->toBeTrue()
        ->and($globalMiddleware[0] ?? null)->toBe(RedactReelHeaders::class)
        ->and($kernel->hasMiddleware(RememberCapturePolicy::class))->toBeTrue()
        ->and($this->app->make(Router::class)->getMiddlewareGroups()['web'])->toContain(CorrelateReelRequest::class)
        ->and($this->app->make(RateLimiter::class)->limiter('reel-grants'))->not->toBeNull();
});
