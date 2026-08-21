<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Contracts;

use Illuminate\Http\Request;
use Stringable;

interface StableUserIdResolver
{
    public function resolve(Request $request, mixed $user): int|string|Stringable|null;
}
