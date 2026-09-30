<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Application;
use App\Models\RecordingSession;
use Illuminate\Support\Facades\URL;

final class ReplayPlayerLink
{
    public function make(Application $application, RecordingSession $session, string $channel, int $start = 0): string
    {
        return URL::temporarySignedRoute(
            'sessions.player',
            now()->addMinutes(5),
            [
                'application' => $application,
                'recordingSession' => $session,
                'channel' => $channel,
                'start' => max(0, $start),
            ],
        );
    }
}
