<?php

namespace App\Http\Controllers;

use App\Exceptions\RetentionRejected;
use App\Models\Application;
use App\Services\RecordingProtection;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
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
        $actor = app(IdentityContext::class);
        abort_unless($actor->canUseProduct(), 403);

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
        $actor = app(IdentityContext::class);
        abort_unless($actor->canUseProduct(), 403);

        try {
            $changed = $protection->unprotect($session->getKey(), $actor);
        } catch (RetentionRejected $rejection) {
            abort($rejection->httpStatus, $rejection->reason);
        }

        return back()->with('retention_status', $changed ? 'recording_unprotected' : 'recording_not_protected');
    }
}
