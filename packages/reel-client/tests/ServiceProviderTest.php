<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\ReelClientServiceProvider;
use ArtisanBuild\ReelClient\Tests\TestCase;

uses(TestCase::class);

it('boots standalone', function (): void {
    expect($this->app->getProvider(ReelClientServiceProvider::class))
        ->toBeInstanceOf(ReelClientServiceProvider::class)
        ->and(config('reel'))->not->toBeNull();
});
