<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use Illuminate\Support\ServiceProvider;

final class ReelClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reel.php', 'reel');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/reel.php' => $this->app->configPath('reel.php'),
            ], 'reel-config');
        }
    }
}
