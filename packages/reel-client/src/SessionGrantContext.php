<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

final readonly class SessionGrantContext
{
    /**
     * @param  non-empty-list<string>  $allowedOrigins
     * @param  array{max_chunks: int, max_compressed_bytes: int, max_chunk_bytes: int}  $maximumCeilings
     */
    public function __construct(
        public string $applicationId,
        public string $credentialId,
        public array $allowedOrigins,
        public string $sessionId,
        public array $maximumCeilings,
        public int $maximumLifetimeSeconds,
    ) {}
}
