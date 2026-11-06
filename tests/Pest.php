<?php

use App\Enums\RecordingSessionStatus;
use App\Models\Application;
use App\Models\RecordingSession;
use App\Services\ReelCredentialScope;
use App\Services\ReplayManifest;
use ArtisanBuild\BuiltForCloud\Actions\CompleteAsymmetricEnrollment;
use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialOwnership;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\MintOptions;
use ArtisanBuild\BuiltForCloud\OwnerCredentialMinter;
use ArtisanBuild\BuiltForCloud\Ownership;
use ArtisanBuild\BuiltForCloud\Rs256PublicKey;
use ArtisanBuild\BuiltForCloud\User as BuiltForCloudUser;
use ArtisanBuild\ReelClient\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * @return array{public: string, private: string}
 */
function testRsaKeyPair(bool $fresh = false): array
{
    static $pair;

    if (! $fresh && is_array($pair)) {
        return $pair;
    }

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($key === false || ! openssl_pkey_export($key, $private)) {
        throw new RuntimeException('Unable to generate a test RSA key pair.');
    }

    $details = openssl_pkey_get_details($key);

    if ($details === false) {
        throw new RuntimeException('Unable to inspect the test RSA key pair.');
    }

    $generated = ['public' => $details['key'], 'private' => $private];

    if (! $fresh) {
        $pair = $generated;
    }

    return $generated;
}

function originSyncCredential(string $token, bool $owner = false): Credential
{
    $credential = resolve(OwnerCredentialMinter::class)->mintFromHash(hash('sha256', $token));

    if ($owner) {
        $ownership = Ownership::current();

        if ($ownership instanceof Ownership) {
            $ownership->forceFill(['owner_credential_id' => $credential->getKey()])->save();
        } else {
            Ownership::query()->create(['owner_credential_id' => $credential->getKey()]);
        }
    }

    return $credential;
}

/** @return array{token: string, credential: Credential} */
function originSyncOwner(): array
{
    $token = 'origin-owner-'.Str::uuid();

    return [
        'token' => $token,
        'credential' => originSyncCredential($token, true),
    ];
}

/** @return array{credential: Credential, code: string} */
function pendingReelCredential(Application $application, ?AuditActor $actor = null): array
{
    $scope = ReelCredentialScope::for($application);
    $mint = resolve(MintCredential::class)(
        $scope->subject,
        new MintOptions(
            kind: CredentialKind::Asymmetric,
            purpose: CredentialPurpose::Signing,
            codeTtlSeconds: 900,
            boundScope: $scope,
        ),
        $actor,
    );

    return [
        'credential' => Credential::query()->findOrFail($mint->summary->id),
        'code' => $mint->secret?->reveal() ?? throw new RuntimeException('Enrollment code was not delivered.'),
    ];
}

/** @param array{public: string, private: string}|null $keyPair */
function activeReelCredential(Application $application, ?array $keyPair = null): Credential
{
    $pending = pendingReelCredential($application);
    $keyPair ??= testRsaKeyPair();
    resolve(CompleteAsymmetricEnrollment::class)(
        $pending['code'],
        ReelCredentialScope::for($application),
        new Rs256PublicKey($keyPair['public']),
    );

    return $pending['credential']->refresh();
}

/** @param list<array<string, mixed>> $events @param array<string, mixed> $attributes */
function makeMcpRecordingSession(Application $application, Credential $credential, array $events = [], array $attributes = []): RecordingSession
{
    $sessionId = $attributes['session_id'] ?? bin2hex(random_bytes(32));
    $manifest = null;
    $manifestChecksum = null;
    $compressedBytes = 0;

    if ($events !== []) {
        $encoded = json_encode($events, JSON_THROW_ON_ERROR)."\n";
        $compressed = gzencode($encoded);
        expect($compressed)->toBeString();
        $key = "reel/chunks/{$application->public_id}/{$sessionId}/replay.jsonl.gz";
        Storage::disk('local')->put($key, $compressed);
        $manifest = [
            'manifest_version' => 1,
            'envelope_version' => Envelope::VERSION,
            'rrweb_version' => Envelope::RRWEB_VERSION,
            'compression' => Envelope::COMPRESSION,
            'objects' => [[
                'key' => $key,
                'checksum' => hash('sha256', $compressed),
                'bytes' => strlen($compressed),
            ]],
            'event_started_at' => 1_000,
            'event_ended_at' => 1_000 + count($events),
            'epoch_count' => 1,
            'chunk_count' => 1,
            'gap_count' => 0,
            'incomplete' => false,
            'incomplete_reasons' => [],
            'compaction_state' => 'ready',
        ];
        $manifestChecksum = resolve(ReplayManifest::class)->checksum($manifest);
        $compressedBytes = strlen($compressed);
    }

    $session = new RecordingSession;
    $session->fill(array_merge([
        'application_id' => $application->getKey(),
        'application_credential_id' => $credential->getKey(),
        'session_id' => $sessionId,
        'grant_id_hash' => hash('sha256', $sessionId),
        'origin' => 'https://monitored.example',
        'protocol_version' => Envelope::VERSION,
        'max_chunks' => 500,
        'max_compressed_bytes' => 10_000_000,
        'max_chunk_bytes' => 1_000_000,
        'chunk_count' => $events === [] ? 0 : 1,
        'compressed_bytes' => $compressedBytes,
        'epoch_count' => 1,
        'started_at' => now(),
        'max_event_time' => now()->addHour(),
        'upload_cutoff_at' => now()->addHour(),
        'ended_at' => now()->addMinute(),
        'maximum_expires_at' => now()->addDays(30),
        'status_changed_at' => now(),
        'is_complete' => true,
        'incomplete_reasons' => [],
        'gap_count' => 0,
        'duration_seconds' => 60,
        'initial_path' => '/start',
        'latest_path' => '/finish',
    ], $attributes));
    $session->forceFill([
        'status' => RecordingSessionStatus::Ready,
        'manifest' => $manifest,
        'manifest_checksum' => $manifestChecksum,
        'compacted_at' => $manifest === null ? null : now(),
    ])->save();

    return $session->fresh(['application']);
}

function testIdentity(BuiltForCloudUser $user, ?string $actorId = null): DomainIdentityContext
{
    return new DomainIdentityContext(
        $actorId ?? (string) $user->getKey(),
        $user->status === 'active' ? $user->role : null,
        AuthorityMode::Standalone,
        1,
        CredentialOwnership::Account,
    );
}

function enrollmentCodeFromHtml(string $html): string
{
    preg_match('/data-testid="enrollment-code-value"[^>]*>([^<]+)</', $html, $matches);

    return html_entity_decode($matches[1] ?? throw new RuntimeException('The immediate response did not contain an enrollment code.'));
}
