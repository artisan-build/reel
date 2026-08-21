<?php

namespace App\Exceptions;

use RuntimeException;

class RetentionRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
    ) {
        parent::__construct($reason);
    }
}
