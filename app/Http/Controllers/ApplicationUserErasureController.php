<?php

namespace App\Http\Controllers;

use App\Exceptions\RetentionRejected;
use App\Models\Application;
use App\Services\UserErasure;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApplicationUserErasureController extends Controller
{
    public function __invoke(Request $request, Application $application, UserErasure $erasure): RedirectResponse
    {
        $validated = $request->validate([
            'application_user_id' => ['required', 'string', 'max:255'],
            'confirmation' => ['required', 'string', 'max:255'],
        ]);
        $actor = resolve(IdentityContext::class);
        abort_unless($actor->canUseProduct(), 403);
        $confirmed = hash_equals($validated['application_user_id'], $validated['confirmation']);

        try {
            $audit = $erasure->erase($application, $validated['application_user_id'], $actor, $confirmed);
        } catch (RetentionRejected $rejection) {
            abort($rejection->httpStatus, $rejection->reason);
        }

        return back()->with(
            'retention_status',
            "erasure_{$audit->outcome}:{$audit->batch_id}:{$audit->deleted_count}:{$audit->failed_count}",
        );
    }
}
