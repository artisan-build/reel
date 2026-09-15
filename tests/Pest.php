<?php

use App\Models\Application;
use App\Services\ReelCredentialScope;
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
use ArtisanBuild\BuiltForCloud\Rs256PublicKey;
use ArtisanBuild\BuiltForCloud\User as BuiltForCloudUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

/** @return array{credential: Credential, code: string} */
function pendingReelCredential(Application $application, ?AuditActor $actor = null): array
{
    $scope = ReelCredentialScope::for($application);
    $mint = app(MintCredential::class)(
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
    app(CompleteAsymmetricEnrollment::class)(
        $pending['code'],
        ReelCredentialScope::for($application),
        new Rs256PublicKey($keyPair['public']),
    );

    return $pending['credential']->refresh();
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
