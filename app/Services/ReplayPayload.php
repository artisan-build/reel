<?php

namespace App\Services;

final readonly class ReplayPayload
{
    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $manifest
     */
    private function __construct(
        public array $events,
        public array $manifest,
        public ?string $diagnostic,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $manifest
     */
    public static function ready(array $events, array $manifest): self
    {
        return new self($events, $manifest, null);
    }

    public static function diagnostic(string $code): self
    {
        return new self([], [], $code);
    }
}
