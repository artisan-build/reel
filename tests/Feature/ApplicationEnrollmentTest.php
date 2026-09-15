<?php

declare(strict_types=1);

use App\Models\Application;
use App\Services\ReelCredentialScope;
use ArtisanBuild\BuiltForCloud\AsymmetricVerificationKeys;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\OnboardingToken;

function enrollmentUrl(Application $application): string
{
    return '/bfc/asymmetric-enrollments/'.$application->public_id;
}

it('enrolls exactly one public key through the package route', function (): void {
    $application = Application::factory()->create();
    $pending = pendingReelCredential($application);
    $before = Credential::query()->count();

    $this->postJson(enrollmentUrl($application), [
        'enrollment_code' => $pending['code'],
        'public_key' => testRsaKeyPair()['public'],
    ])->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'credential_id' => $pending['credential']->id,
            'algorithm' => 'RS256',
        ]);

    $credential = $pending['credential']->refresh();
    $keys = resolve(AsymmetricVerificationKeys::class)->for(ReelCredentialScope::for($application));

    expect($credential->status)->toBe(CredentialStatus::Active)
        ->and($credential->public_key)->toContain('BEGIN PUBLIC KEY')
        ->and($credential->public_key)->not->toContain('PRIVATE KEY')
        ->and($credential->activated_at)->not->toBeNull()
        ->and(Credential::query()->count())->toBe($before)
        ->and($keys)->toHaveCount(1)
        ->and($keys[0]->credentialId)->toBe($credential->id);
});

it('returns an indistinct 404 for reuse, wrong application, and disabled application without mutation', function (string $case): void {
    $application = Application::factory()->create();
    $pending = pendingReelCredential($application);
    $target = $application;

    if ($case === 'reuse') {
        $this->postJson(enrollmentUrl($application), [
            'enrollment_code' => $pending['code'],
            'public_key' => testRsaKeyPair()['public'],
        ])->assertCreated();
    } elseif ($case === 'wrong application') {
        $target = Application::factory()->create();
    } else {
        $application->update(['ingest_enabled' => false]);
    }

    $before = [
        'credentials' => Credential::query()->count(),
        'consumed' => OnboardingToken::query()->whereNotNull('consumed_at')->count(),
    ];

    $this->postJson(enrollmentUrl($target), [
        'enrollment_code' => $pending['code'],
        'public_key' => testRsaKeyPair()['public'],
    ])->assertNotFound();

    expect(Credential::query()->count())->toBe($before['credentials'])
        ->and(OnboardingToken::query()->whereNotNull('consumed_at')->count())->toBe($before['consumed']);
})->with(['reuse', 'wrong application', 'disabled application']);

it('returns 422 for private material and non-closed payloads without consuming enrollment', function (array $payload): void {
    $application = Application::factory()->create();
    $pending = pendingReelCredential($application);
    $payload['enrollment_code'] ??= $pending['code'];

    $this->postJson(enrollmentUrl($application), $payload)->assertUnprocessable();

    expect($pending['credential']->refresh()->status)->toBe(CredentialStatus::Pending)
        ->and($pending['credential']->public_key)->toBeNull()
        ->and(OnboardingToken::query()->where('durable_credential_id', $pending['credential']->id)->value('consumed_at'))->toBeNull();
})->with([
    'private key marker' => fn (): array => ['public_key' => testRsaKeyPair()['private']],
    'malformed public key' => fn (): array => ['public_key' => 'not-a-pem-key'],
    'oversize public key' => fn (): array => ['public_key' => str_repeat('A', 16 * 1024 + 1)],
    'unexpected algorithm' => fn (): array => ['public_key' => testRsaKeyPair()['public'], 'algorithm' => 'RS256'],
    'non-string code' => fn (): array => ['enrollment_code' => ['not-a-string'], 'public_key' => testRsaKeyPair()['public']],
]);
