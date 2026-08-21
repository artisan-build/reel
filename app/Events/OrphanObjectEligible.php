<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OrphanObjectEligible
{
    use Dispatchable;

    public function __construct(
        public readonly string $objectKey,
        public readonly int $observedLastModified,
    ) {}
}
