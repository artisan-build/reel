<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class ReplayPlayerUrlController extends Controller
{
    public function __invoke(Request $request, Application $application, string $recordingSession): JsonResponse
    {
        $session = $application->recordingSessions()
            ->where('session_id', $recordingSession)
            ->firstOrFail();
        $channel = $request->query('channel');
        abort_unless(is_string($channel) && preg_match('/^[a-f0-9]{96}$/', $channel) === 1, 404);

        $url = URL::temporarySignedRoute(
            'sessions.player',
            now()->addMinutes(5),
            [
                'application' => $application,
                'recordingSession' => $session,
                'channel' => $channel,
                'start' => max(0, $request->integer('start')),
            ],
        );

        return response()->json(['url' => $url])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
