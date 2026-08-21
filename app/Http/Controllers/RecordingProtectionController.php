<?php

namespace App\Http\Controllers;

use App\Exceptions\RetentionRejected;
use App\Models\Application;
use App\Models\User;
use App\Services\RecordingProtection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecordingProtectionController extends Controller
{
    public function store(
        Request $request,
        Application $application,
        string $recordingSession,
        RecordingProtection $protection,
    ): RedirectResponse {
        $session = $application->recordingSessions()->where('session_id', $recordingSession)->firstOrFail();
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $changed = $protection->protect($session->getKey(), $actor);
        } catch (RetentionRejected $rejection) {
            abort($rejection->httpStatus, $rejection->reason);
        }

        return back()->with('retention_status', $changed ? 'recording_protected' : 'recording_already_protected');
    }

    public function destroy(
        Request $request,
        Application $application,
        string $recordingSession,
        RecordingProtection $protection,
    ): RedirectResponse {
        $session = $application->recordingSessions()->where('session_id', $recordingSession)->firstOrFail();
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $changed = $protection->unprotect($session->getKey(), $actor);
        } catch (RetentionRejected $rejection) {
            abort($rejection->httpStatus, $rejection->reason);
        }

        return back()->with('retention_status', $changed ? 'recording_unprotected' : 'recording_not_protected');
    }
}
