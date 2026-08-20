<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CompactionCandidateWritten
{
    use Dispatchable;

    public function __construct(
        public readonly int $recordingSessionId,
        public readonly string $candidateKey,
    ) {}
}
