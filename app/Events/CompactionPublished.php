<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CompactionPublished
{
    use Dispatchable;

    public function __construct(public readonly int $recordingSessionId) {}
}
