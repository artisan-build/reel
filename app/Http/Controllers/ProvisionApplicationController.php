<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProvisionApplication;
use App\Exceptions\ApplicationProvisioningConflict;
use ArtisanBuild\BuiltForCloud\Exceptions\CredentialVerbRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProvisionApplicationController extends Controller
{
    public function __invoke(Request $request, ProvisionApplication $provision): JsonResponse
    {
        /** @var array{request_id: string, name: string} $validated */
        $validated = $request->validate([
            'request_id' => ['required', 'string', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $actorCredentialId = $request->attributes->get('bfc.actor_credential_id');
        abort_unless(is_string($actorCredentialId) && $actorCredentialId !== '', 401);

        try {
            $result = $provision($validated['request_id'], $validated['name'], $actorCredentialId);
        } catch (ApplicationProvisioningConflict) {
            return response()->json(['message' => 'The request id conflicts with an existing provisioning request.'], 409);
        } catch (CredentialVerbRefused) {
            return response()->json(['message' => 'Credential issuance is forbidden.'], 403);
        }

        if ($result === null) {
            return response()->json(['message' => 'The enrollment replay window has expired.'], 409);
        }

        return response()->json($result->response(), $result->created ? 201 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
