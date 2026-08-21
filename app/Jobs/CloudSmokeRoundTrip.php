<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CloudSmokeRoundTrip implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $path,
        public readonly string $probe,
        public readonly string $payload,
        public readonly int $expiresAt,
    ) {}

    public function handle(): void
    {
        if (now()->getTimestamp() > $this->expiresAt) {
            return;
        }

        try {
            $disk = Storage::disk((string) config('filesystems.default'));

            if (! $disk->exists($this->path) || $disk->get($this->path) !== $this->probe) {
                return;
            }

            $disk->put($this->path, $this->payload);
        } catch (Throwable) {
            // The command detects the unchanged probe without leaving a failed queue job behind.
        }
    }
}
