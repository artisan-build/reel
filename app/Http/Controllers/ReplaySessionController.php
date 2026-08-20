<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ReplaySessionController extends Controller
{
    public function __invoke(Request $request, Application $application, string $recordingSession): View
    {
        $recording = $application->recordingSessions()
            ->where('session_id', $recordingSession)
            ->with([
                'application',
                'epochs' => fn ($query) => $query->orderBy('ordinal'),
                'markers' => fn ($query) => $query->orderBy('occurred_at'),
                'transitions' => fn ($query) => $query->orderBy('transitioned_at'),
            ])
            ->firstOrFail();
        $channelNonce = bin2hex(random_bytes(48));
        $timestamp = max(0, $request->integer('t'));
        $playerUrl = URL::temporarySignedRoute(
            'sessions.player',
            now()->addMinutes(5),
            [
                'application' => $application,
                'recordingSession' => $recording,
                'channel' => $channelNonce,
                'start' => $timestamp,
            ],
        );
        $reasons = is_array($recording->incomplete_reasons)
            ? array_values(array_filter($recording->incomplete_reasons, 'is_string'))
            : [];
        $uncertaintyReasons = array_values(array_filter(
            $reasons,
            fn (string $reason): bool => Str::startsWith($reason, 'missing_terminal_sequence:'),
        ));
        $detectedMissingDataReasons = array_values(array_filter(
            $reasons,
            fn (string $reason): bool => ! Str::startsWith($reason, 'missing_terminal_sequence:'),
        ));

        return view('sessions.show', compact(
            'channelNonce',
            'detectedMissingDataReasons',
            'playerUrl',
            'recording',
            'uncertaintyReasons',
        ));
    }
}
