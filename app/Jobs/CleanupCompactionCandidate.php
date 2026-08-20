<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class CleanupCompactionCandidate implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $disk,
        public readonly string $candidateKey,
    ) {}

    public function handle(): void
    {
        Storage::disk($this->disk)->delete($this->candidateKey);
    }
}
