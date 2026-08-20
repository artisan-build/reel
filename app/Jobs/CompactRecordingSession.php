<?php

namespace App\Jobs;

use App\Services\RecordingCompactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CompactRecordingSession implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $recordingSessionId) {}

    public function handle(RecordingCompactor $compactor): void
    {
        $compactor->compact($this->recordingSessionId);
    }

    public function failed(?Throwable $exception): void
    {
        app(RecordingCompactor::class)->markFailed($this->recordingSessionId);
    }
}
