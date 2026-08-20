<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Facades;

use Illuminate\Support\Facades\Facade;

final class Reel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ArtisanBuild\ReelClient\Reel::class;
    }
}
