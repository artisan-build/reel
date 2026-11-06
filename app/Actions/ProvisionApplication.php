<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ProvisionedApplication;
use App\Exceptions\ApplicationProvisioningConflict;
use App\Models\Application;
use App\Models\ApplicationProvisioningRequest;
use App\Services\EnrollmentCodeCipher;
use App\Services\ReelCredentialScope;
use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\MintOptions;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ProvisionApplication
{
    private const int CODE_TTL_SECONDS = 900;

    public function __construct(
        private MintCredential $mint,
        private EnrollmentCodeCipher $codes,
    ) {}

    public function __invoke(string $requestId, string $name, string $actorCredentialId): ?ProvisionedApplication
    {
        return DB::transaction(function () use ($requestId, $name, $actorCredentialId): ?ProvisionedApplication {
            $this->lockRequestId($requestId);

            $existing = ApplicationProvisioningRequest::query()->find($requestId);

            if ($existing instanceof ApplicationProvisioningRequest) {
                return $this->replay($existing, $name);
            }

            $application = Application::query()->create([
                'name' => $name,
                'allowed_origins' => [],
                'ingest_enabled' => true,
            ]);
            $scope = ReelCredentialScope::for($application);
            $expiresAt = now()->addSeconds(self::CODE_TTL_SECONDS);
            $enrollment = ($this->mint)(
                $scope->subject,
                new MintOptions(
                    kind: CredentialKind::Asymmetric,
                    purpose: CredentialPurpose::Signing,
                    codeTtlSeconds: self::CODE_TTL_SECONDS,
                    boundScope: $scope,
                ),
                AuditActor::operatorIntegration($actorCredentialId),
            );
            $secret = $enrollment->secret;

            if ($secret === null) {
                throw new RuntimeException('The enrollment code was not delivered.');
            }

            $code = $secret->reveal();

            ApplicationProvisioningRequest::query()->create([
                'id' => $requestId,
                'name' => $name,
                'application_id' => $application->getKey(),
                'enrollment_code_ciphertext' => $this->codes->encrypt($code),
                'enrollment_expires_at' => $expiresAt,
            ]);

            return new ProvisionedApplication($application->public_id, $code, true);
        });
    }

    private function lockRequestId(string $requestId): void
    {
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$requestId]);
    }

    private function replay(ApplicationProvisioningRequest $request, string $name): ?ProvisionedApplication
    {
        if (! hash_equals($request->name, $name)) {
            throw new ApplicationProvisioningConflict('The request id has already been used with different application facts.');
        }

        if ($request->enrollment_expires_at->isPast() || $request->enrollment_code_ciphertext === null) {
            $request->forceFill(['enrollment_code_ciphertext' => null])->save();

            return null;
        }

        $application = $request->application()->firstOrFail();

        return new ProvisionedApplication(
            $application->public_id,
            $this->codes->decrypt($request->enrollment_code_ciphertext),
            false,
        );
    }
}
