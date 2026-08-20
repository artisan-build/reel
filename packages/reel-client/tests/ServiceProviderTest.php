<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\ReelClientServiceProvider;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

it('boots standalone', function (): void {
    $this->app->register(ReelClientServiceProvider::class);

    expect($this->app->getProvider(ReelClientServiceProvider::class))
        ->toBeInstanceOf(ReelClientServiceProvider::class)
        ->and(config('reel'))->not->toBeNull();
});
