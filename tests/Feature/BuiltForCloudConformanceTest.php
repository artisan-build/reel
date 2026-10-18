<?php

declare(strict_types=1);

use App\Jobs\CleanupCompactionCandidate;
use App\Jobs\CloudSmokeRoundTrip;
use App\Jobs\CompactRecordingSession;
use App\Jobs\DeleteUserErasureBatch;
use App\Mcp\ReelMcpServer;
use App\Mcp\Tools\SessionContentTool;
use App\Mcp\Tools\SessionDeepLinkTool;
use App\Mcp\Tools\SessionsTool;
use App\Models\Application;
use App\Models\ReplayView;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleReKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleRetireKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\CreateAdminCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialActivateCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialListCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialMintCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRevokeCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRotateCommand;
use ArtisanBuild\BuiltForCloud\Commands\FreshCommand;
use ArtisanBuild\BuiltForCloud\Commands\HmacRewrapCommand;
use ArtisanBuild\BuiltForCloud\Commands\InstallOperatorCredentialCommand;
use ArtisanBuild\BuiltForCloud\Commands\OutboxDrainCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipMintClaimCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipRemintOwnerTokenCommand;
use ArtisanBuild\BuiltForCloud\Commands\PruneCredentialAuthorizationsCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootEnsureCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand;
use ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand;
use ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedEnforcement;
use Composer\InstalledVersions;
use Laravel\Mcp\ResponseFactory;

uses(ContractAssertions::class);

it('honors delegated callers before running fleet conformance', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $packageRoot = InstalledVersions::getInstallPath('artisan-build/built-for-cloud');
    expect($packageRoot)->toBeString();

    $sorted = static function (array $members): array {
        sort($members);

        return $members;
    };
    $tools = $sorted([
        SessionsTool::class,
        SessionContentTool::class,
        SessionDeepLinkTool::class,
    ]);
    $arguments = [
        SessionContentTool::class => [
            'application' => 'x',
            'session_id' => str_repeat('a', 64),
        ],
        SessionDeepLinkTool::class => [
            'application' => 'x',
            'session_id' => str_repeat('a', 64),
        ],
    ];
    $negativeAuthorization = static function (
        ActingPrincipal $first,
        ActingPrincipal $second,
        Closure $invoke,
    ): void {
        expect($first->identifier())->not->toBe($second->identifier());

        $application = Application::factory()->create();
        $session = makeMcpRecordingSession($application, activeReelCredential($application));
        ReplayView::query()->create([
            'actor_id' => (string) $first->identifier(),
            'application_id' => $application->getKey(),
            'recording_session_id' => $session->getKey(),
            'viewed_at' => now(),
        ]);
        $response = $invoke(SessionsTool::class, [
            'application' => $application->public_id,
            'watched' => 'yes',
        ]);

        expect($response)->toBeInstanceOf(ResponseFactory::class)
            ->and($response->getStructuredContent()['sessions'])->toBe([]);
    };

    McpDelegatedEnforcement::assertHonors(
        ReelMcpServer::class,
        $tools,
        negativeAuthorization: $negativeAuthorization,
        arguments: $arguments,
    );
    $probe = McpDelegatedEnforcement::probe(
        ReelMcpServer::class,
        negativeAuthorization: $negativeAuthorization,
        arguments: $arguments,
    );

    expect($probe['tools'])->toBe($tools)
        ->and($probe['invoked'])->toBe($tools)
        ->and($probe['refusals'])->toBe([])
        ->and($probe['principal_mismatches'])->toBe([])
        ->and($probe['errors'])->toBe([])
        ->and($probe['unreached'])->toBe([])
        ->and($probe['negative_authorization'])->toBe('passed')
        ->and($probe['violations'])->toBe([]);

    $purposeMappings = [];
    foreach ((array) config('built-for-cloud.credentials.app_purposes', []) as $appPurpose => $purpose) {
        $purposeMappings[$appPurpose] = CredentialPurpose::from($purpose);
    }
    ksort($purposeMappings);
    $sourceRoots = [app_path(), base_path('bootstrap'), config_path(), base_path('routes')];
    sort($sourceRoots);
    $providerFiles = [app_path('Providers/AppServiceProvider.php'), $packageRoot.'/src/BuiltForCloudServiceProvider.php'];
    sort($providerFiles);
    $expected = [
        'runtime.meta' => [],
        'runtime.auth_schema' => [],
        'runtime.credential_listing' => [],
        'runtime.transport_parity' => [],
        'thin_host' => [],
        'credential_paths' => $sorted([
            'path:Basic|ArtisanBuild\BuiltForCloud\Auth\BasicAuthenticator',
            'path:Bearer|ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator',
            'path:HMAC|Http\Middleware\VerifyHmacSignature+Hmac\HmacVerifier',
            'path:MCP|Http\Middleware\AuthenticateMcp:store-bearer+v4.public',
            'path:asymmetric|Actions\MintCredential::mintEnrollment+CompleteAsymmetricEnrollment+AsymmetricVerificationKeys',
            'path:device|Http\Controllers\DeviceAuthorizations+Actions\StartDeviceAuthorization/DecideDeviceAuthorization/PollDeviceAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:enrollment|OnboardingToken+POST:/bfc/claim,/bfc/onboarding/issue,/exchange,/verify',
            'path:loopback|Http\Controllers\LoopbackAuthorizations+Actions\StartLoopbackAuthorization/DecideLoopbackAuthorization/ExchangeLoopbackAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:system|SubjectType::Operator/Application/Installation+AuditActorType::CliOperator',
        ]),
        'credential_writers' => $sorted([
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSecretBearing',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithPendingSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithSecret',
            'ArtisanBuild\BuiltForCloud\OwnerCredentialMinter::mintFromHash',
            'ArtisanBuild\BuiltForCloud\UnifiedStoreCredentialMinter::mint',
        ]),
        'legacy_removal' => [],
        'system_authority' => $sorted([
            CleanupCompactionCandidate::class,
            CloudSmokeRoundTrip::class,
            CompactRecordingSession::class,
            ConsoleReKeyCommand::class,
            ConsoleRetireKeyCommand::class,
            CreateAdminCommand::class,
            CredentialActivateCommand::class,
            CredentialListCommand::class,
            CredentialMintCommand::class,
            CredentialRevokeCommand::class,
            CredentialRotateCommand::class,
            DeleteUserErasureBatch::class,
            DeliverOwnershipWebhook::class,
            FreshCommand::class,
            HmacRewrapCommand::class,
            InstallOperatorCredentialCommand::class,
            OutboxDrainCommand::class,
            OwnershipMintClaimCommand::class,
            OwnershipRemintOwnerTokenCommand::class,
            PruneCredentialAuthorizationsCommand::class,
            SigningRootEnsureCommand::class,
            SigningRootProvisionCommand::class,
            SubjectOffboardCommand::class,
            WarnExpiringCredentialsCommand::class,
            'Closure@package/src/SystemAuthoritySchedule.php:27',
        ]),
        'no_signing_path' => [],
        'ui_config_reads' => $sorted([
            'ArtisanBuild\BuiltForCloud\AppPurposeRegistry|built-for-cloud.credentials.app_purposes|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\ManageTransitions|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.installation_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.member_management|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.personal_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.session_management|1',
            'ArtisanBuild\BuiltForCloud\LandingManifest|built-for-cloud.manifest|1',
            'ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationStore|built-for-cloud.manifest.slug|1',
            'ArtisanBuild\BuiltForCloud\UiCredentialPurposes|built-for-cloud.ui.credential_purposes|1',
        ]),
        'mcp_delegated' => $tools,
    ];

    $report = (new FleetConformance($this))->inspect(new ConsumerConformance(
        consumer: 'reel',
        consumerRoot: base_path(),
        packageRoot: $packageRoot,
        sourceRoots: $sourceRoots,
        providerFiles: $providerFiles,
        runtimeAssertions: ['auth_schema', 'credential_listing', 'meta', 'transport_parity'],
        capabilities: ['credentials', 'mcp-delegated', 'mcp-effect-scoped', 'mcp-serve', 'tokens'],
        purposeMappings: $purposeMappings,
        mcpServer: ReelMcpServer::class,
        expected: $expected,
    ));

    $scheduledCommands = [
        'reel:finalize-sessions',
        'reel:resume-erasures --apply',
        'reel:retain-sessions',
        'reel:retry-deletions --apply',
        'reel:sweep-orphans',
    ];
    $otherViolations = collect($report->families)
        ->except('system_authority')
        ->flatMap(static fn ($family): array => $family->violations)
        ->all();
    $systemAuthorityViolations = collect($report->families['system_authority']->violations)
        ->map(static function (string $violation) use ($scheduledCommands): string {
            foreach (['unexpected:', 'uninspectable-schedule:'] as $prefix) {
                foreach ($scheduledCommands as $command) {
                    if (str_starts_with($violation, $prefix)
                        && str_ends_with($violation, "artisan' ".$command)) {
                        return $prefix.$command;
                    }
                }
            }

            return $violation;
        })
        ->sort()
        ->values()
        ->all();
    $expectedSystemAuthorityViolations = [];
    foreach ($scheduledCommands as $command) {
        $expectedSystemAuthorityViolations[] = 'unexpected:'.$command;
        $expectedSystemAuthorityViolations[] = 'uninspectable-schedule:'.$command;
    }
    sort($expectedSystemAuthorityViolations);

    expect($report->passed)->toBeFalse()
        ->and(array_keys($report->families))->toBe(ConsumerConformance::FAMILIES)
        ->and($otherViolations)->toBe([])
        ->and($report->families['system_authority']->applicability)->toBe('applicable')
        ->and($systemAuthorityViolations)->toBe($expectedSystemAuthorityViolations);
});
