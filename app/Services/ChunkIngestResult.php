<?php

namespace App\Services;

final readonly class ChunkIngestResult
{
    public function __construct(
        public bool $duplicate,
        public string $origin,
        public bool $conflict = false,
    ) {}
}
