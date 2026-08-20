<?php

namespace App\Exceptions;

use RuntimeException;

class IngestRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status,
    ) {
        parent::__construct('The recording chunk was rejected.');
    }
}
