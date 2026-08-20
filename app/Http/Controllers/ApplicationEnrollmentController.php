<?php

namespace App\Http\Controllers;

use App\Enums\CredentialStatus;
use App\Http\Requests\EnrollApplicationRequest;
use App\Models\Application;
use App\Models\ApplicationCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ApplicationEnrollmentController extends Controller
{
    public function store(EnrollApplicationRequest $request, Application $application): JsonResponse
    {
        abort_unless($application->ingest_enabled, 403, 'Enrollment is disabled for this application.');

        $validated = $request->validated();

        $credential = DB::transaction(function () use ($application, $validated): ApplicationCredential {
            $credentials = $application->credentials()
                ->whereNotNull('enrollment_code_hash')
                ->lockForUpdate()
                ->get();

            $credential = $credentials->first(fn (ApplicationCredential $candidate): bool => Hash::check(
                $validated['enrollment_code'],
                $candidate->enrollment_code_hash,
            ));

            if (! $credential instanceof ApplicationCredential) {
                throw ValidationException::withMessages([
                    'enrollment_code' => 'The enrollment code is invalid or has already been used.',
                ]);
            }

            if ($credential->status === CredentialStatus::Revoked) {
                throw ValidationException::withMessages([
                    'enrollment_code' => 'The credential associated with this enrollment code is revoked.',
                ]);
            }

            if ($credential->enrollment_expires_at === null || $credential->enrollment_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'enrollment_code' => 'The enrollment code has expired.',
                ]);
            }

            $credential->update([
                'public_key' => $validated['public_key'],
                'algorithm' => $validated['algorithm'],
                'status' => CredentialStatus::Active,
                'enrollment_code_hash' => null,
                'enrolled_at' => now(),
            ]);

            return $credential;
        });

        return response()->json([
            'credential_id' => $credential->getKey(),
            'application_id' => $application->public_id,
            'algorithm' => $credential->algorithm,
        ], 201);
    }
}
