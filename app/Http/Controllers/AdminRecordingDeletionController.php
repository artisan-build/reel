<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use App\Services\RecordingDeletion;
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
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_admin, 403);

        if (! $deletion->delete($session->getKey(), 'administrator_deleted', $actor)) {
            return back()->withErrors(['retention' => 'recording_deletion_incomplete']);
        }

        return redirect()->route('sessions.index')->with('retention_status', 'recording_deleted');
    }
}
