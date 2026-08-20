<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Tests;

use ArtisanBuild\ReelClient\ReelClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('session.driver', 'array');
        $app['config']->set('reel.host_mode', true);
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ReelClientServiceProvider::class];
    }
}
