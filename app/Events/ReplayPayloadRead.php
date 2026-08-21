<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ReplayPayloadRead
{
    use Dispatchable;

    public function __construct(public readonly int $recordingSessionId) {}
}
