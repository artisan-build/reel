<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Services\RecordingDeletion;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminRecordingDeletionController extends Controller
{
    public function __invoke(
        Request $request,
        Application $application,
        string $recordingSession,
        RecordingDeletion $deletion,
    ): RedirectResponse {
        $session = $application->recordingSessions()->where('session_id', $recordingSession)->firstOrFail();
        $actor = app(IdentityContext::class);
        abort_unless($actor->canUseProduct(), 403);

        if (! $deletion->delete($session->getKey(), 'administrator_deleted', $actor)) {
            return back()->withErrors(['retention' => 'recording_deletion_incomplete']);
        }

        return to_route('sessions.index')->with('retention_status', 'recording_deleted');
    }
}
