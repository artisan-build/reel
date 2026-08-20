<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use ArtisanBuild\ReelClient\Console\InstallCommand;
use ArtisanBuild\ReelClient\Http\Middleware\PreventReelCapture;
use ArtisanBuild\ReelClient\Http\Middleware\PrivateReelSurface;
use ArtisanBuild\ReelClient\Http\Middleware\RememberCapturePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class ReelClientServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reel.php', 'reel');
        $this->app->singleton(Reel::class);
        $this->app->singleton(EnvironmentFile::class);
    }

    public function boot(): void
    {
        $this->registerRoutePolicy();
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'reel-client');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'reel');

        RateLimiter::for('reel-grants', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->session()->getId().'|'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
            $this->publishes([
                __DIR__.'/../config/reel.php' => $this->app->configPath('reel.php'),
            ], 'reel-config');
        }
    }

    private function registerRoutePolicy(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('reel.hidden', PreventReelCapture::class);
        $router->aliasMiddleware('reel.private', PrivateReelSurface::class);
        $kernel = $this->app->make(HttpKernelContract::class);

        if ($kernel instanceof HttpKernel) {
            $kernel->pushMiddleware(RememberCapturePolicy::class);
        }

        Router::macro('hiddenFromReel', function (): RouteRegistrar {
            /** @var Router $this */
            return (new RouteRegistrar($this))
                ->attribute('metadata', ['reel' => ['hidden' => true]])
                ->attribute('middleware', PreventReelCapture::class);
        });

        RouteRegistrar::macro('hiddenFromReel', function (): RouteRegistrar {
            /** @var RouteRegistrar $this */
            return $this
                ->attribute('metadata', ['reel' => ['hidden' => true]])
                ->attribute('middleware', PreventReelCapture::class);
        });

        Route::macro('hiddenFromReel', function (): Route {
            /** @var Route $this */
            return $this
                ->metadata(['reel' => ['hidden' => true]])
                ->middleware(PreventReelCapture::class);
        });
    }
}
