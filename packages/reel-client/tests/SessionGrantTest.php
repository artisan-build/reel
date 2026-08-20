<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\IssuedSessionSet;
use ArtisanBuild\ReelClient\KeyMaterial;
use ArtisanBuild\ReelClient\SessionGrant;
use ArtisanBuild\ReelClient\SessionGrantContext;
use ArtisanBuild\ReelClient\SessionGrantVerifier;
use ArtisanBuild\ReelClient\Tests\TestCase;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HmacSha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Psr\Clock\ClockInterface;

uses(TestCase::class);

function reelTestKeyPair(): array
{
    static $key;

    return $key ??= KeyMaterial::generate();
}

function reelSignedGrant(array $overrides = []): string
{
    $key = reelTestKeyPair();
    $signingKey = $overrides['signing_key'] ?? $key;
    $now = $overrides['now'] ?? new DateTimeImmutable('-1 second');
    $expires = $overrides['expires'] ?? new DateTimeImmutable('+5 minutes');
    $configuration = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::plainText($signingKey['private']),
        InMemory::plainText($signingKey['public']),
    );

    return $configuration->builder()
        ->withHeader('typ', $overrides['typ'] ?? SessionGrant::TYPE)
        ->issuedBy($overrides['issuer'] ?? SessionGrant::ISSUER)
        ->permittedFor($overrides['audience'] ?? SessionGrant::AUDIENCE)
        ->identifiedBy($overrides['grant_id'] ?? 'grant-id')
        ->issuedAt($now)
        ->canOnlyBeUsedAfter($overrides['not_before'] ?? $now)
        ->expiresAt($expires)
        ->withClaim('application_id', $overrides['application_id'] ?? 'app-id')
        ->withClaim('credential_id', $overrides['credential_id'] ?? $key['credential_id'])
        ->withClaim('session_id', $overrides['session_id'] ?? str_repeat('a', 64))
        ->withClaim('origin', $overrides['origin'] ?? 'https://host.example')
        ->withClaim('protocol_version', $overrides['protocol_version'] ?? 1)
        ->withClaim('max_event_time', $overrides['max_event_time'] ?? $expires->getTimestamp() - 60)
        ->withClaim('ceilings', $overrides['ceilings'] ?? [
            'max_chunks' => 10,
            'max_compressed_bytes' => 1000,
            'max_chunk_bytes' => 100,
        ])
        ->getToken($configuration->signer(), $configuration->signingKey())
        ->toString();
}

function reelGrantContext(string $sessionId = '', array $overrides = []): SessionGrantContext
{
    $sessionId = $sessionId === '' ? str_repeat('a', 64) : $sessionId;

    return new SessionGrantContext(
        applicationId: $overrides['application_id'] ?? 'app-id',
        credentialId: $overrides['credential_id'] ?? reelTestKeyPair()['credential_id'],
        allowedOrigins: $overrides['allowed_origins'] ?? ['https://host.example'],
        sessionId: $sessionId,
        maximumCeilings: $overrides['maximum_ceilings'] ?? [
            'max_chunks' => 100,
            'max_compressed_bytes' => 10_000,
            'max_chunk_bytes' => 1000,
        ],
        maximumLifetimeSeconds: $overrides['maximum_lifetime'] ?? 1860,
    );
}

beforeEach(function (): void {
    $key = reelTestKeyPair();
    config()->set([
        'reel.url' => 'https://reel.example',
        'reel.application_id' => 'app-id',
        'reel.private_key' => KeyMaterial::encodePrivateKey($key['private']),
        'reel.grant.max_sessions_per_visitor' => 3,
    ]);
});

it('issues an explicitly typed asymmetric grant only after consent', function (): void {
    $this->postJson(route('reel.session-grants.store'), [])->assertUnprocessable();

    $response = $this->withSession(['reel.current_page_hidden' => false])
        ->postJson(route('reel.session-grants.store'), ['consent' => true])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $token = (new SessionGrantVerifier)->verify(
        $response->json('grant'),
        reelTestKeyPair()['public'],
        reelGrantContext((string) $response->json('session_id'), [
            'allowed_origins' => ['http://localhost'],
            'maximum_ceilings' => [
                'max_chunks' => (int) config('reel.grant.max_chunks'),
                'max_compressed_bytes' => (int) config('reel.grant.max_compressed_bytes'),
                'max_chunk_bytes' => (int) config('reel.grant.max_chunk_bytes'),
            ],
        ]),
    );

    expect($token->headers()->get('typ'))->toBe(SessionGrant::TYPE)
        ->and($token->headers()->get('alg'))->toBe(SessionGrant::ALGORITHM)
        ->and($token->claims()->get('iss'))->toBe(SessionGrant::ISSUER)
        ->and($token->claims()->get('aud'))->toBe([SessionGrant::AUDIENCE])
        ->and($token->claims()->get('application_id'))->toBe('app-id')
        ->and($token->claims()->get('credential_id'))->toBe(reelTestKeyPair()['credential_id'])
        ->and($token->claims()->get('session_id'))->toBe($response->json('session_id'))
        ->and($token->claims()->get('origin'))->toBe('http://localhost')
        ->and($token->claims()->get('protocol_version'))->toBe(1)
        ->and($token->claims()->get('jti'))->toBeString()->not->toBeEmpty()
        ->and($token->claims()->get('max_event_time'))->toBeInt()
        ->and($token->claims()->get('ceilings'))->toHaveKeys([
            'max_chunks', 'max_compressed_bytes', 'max_chunk_bytes',
        ]);
});

it('ignores a browser supplied session id and records a bounded expiring server set', function (): void {
    $browserId = str_repeat('b', 64);
    $serverIds = [];

    foreach (range(1, 5) as $attempt) {
        $response = $this->postJson(route('reel.session-grants.store'), [
            'consent' => true,
            'session_id' => $browserId,
        ])->assertOk();
        $serverIds[] = $response->json('session_id');
    }

    $issued = session('reel.issued_sessions');
    expect($serverIds)->each->toMatch('/^[a-f0-9]{64}$/')
        ->and($serverIds)->not->toContain($browserId)
        ->and(array_unique($serverIds))->toHaveCount(5)
        ->and($issued)->toHaveCount(3)
        ->and(array_keys($issued))->toBe(array_slice($serverIds, -3));

    foreach ($issued as $entry) {
        expect($entry['expires_at'])->toBeGreaterThan(time())
            ->and(array_keys($entry))->toBe(['expires_at', 'issued_at', 'last_active_at', 'path']);
    }
});

it('does not retain query strings or fragments in issuance activity paths', function (): void {
    $response = $this->postJson(route('reel.session-grants.store'), [
        'consent' => true,
        'path' => '/checkout?token=private#fragment',
    ])->assertOk();

    expect(session('reel.issued_sessions')[$response->json('session_id')]['path'])->toBeNull();
});

it('rejects none and symmetric algorithm substitutions', function (): void {
    $encode = fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $none = $encode(['typ' => SessionGrant::TYPE, 'alg' => 'none']).'.'.$encode(['iss' => SessionGrant::ISSUER]).'.';

    $symmetric = Configuration::forSymmetricSigner(
        new HmacSha256,
        InMemory::plainText(str_repeat('symmetric-secret-', 4)),
    );
    $swapped = $symmetric->builder()
        ->withHeader('typ', SessionGrant::TYPE)
        ->issuedBy(SessionGrant::ISSUER)
        ->permittedFor(SessionGrant::AUDIENCE)
        ->issuedAt(new DateTimeImmutable('-1 second'))
        ->canOnlyBeUsedAfter(new DateTimeImmutable('-1 second'))
        ->expiresAt(new DateTimeImmutable('+1 minute'))
        ->getToken($symmetric->signer(), $symmetric->signingKey())
        ->toString();

    $verifier = new SessionGrantVerifier;
    expect(fn () => $verifier->verify($none, reelTestKeyPair()['public'], reelGrantContext()))->toThrow(DomainException::class)
        ->and(fn () => $verifier->verify($swapped, reelTestKeyPair()['public'], reelGrantContext()))->toThrow(DomainException::class);
});

it('rejects an otherwise valid grant signed by a different rsa key', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['signing_key' => KeyMaterial::generate()]),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects an hs256 grant signed with the rsa public key as its hmac secret', function (): void {
    $key = reelTestKeyPair();
    $now = new DateTimeImmutable('-1 second');
    $expires = new DateTimeImmutable('+5 minutes');
    $configuration = Configuration::forSymmetricSigner(
        new HmacSha256,
        InMemory::plainText($key['public']),
    );
    $grant = $configuration->builder()
        ->withHeader('typ', SessionGrant::TYPE)
        ->issuedBy(SessionGrant::ISSUER)
        ->permittedFor(SessionGrant::AUDIENCE)
        ->identifiedBy('grant-id')
        ->issuedAt($now)
        ->canOnlyBeUsedAfter($now)
        ->expiresAt($expires)
        ->withClaim('application_id', 'app-id')
        ->withClaim('credential_id', $key['credential_id'])
        ->withClaim('session_id', str_repeat('a', 64))
        ->withClaim('origin', 'https://host.example')
        ->withClaim('protocol_version', 1)
        ->withClaim('max_event_time', $expires->getTimestamp() - 60)
        ->withClaim('ceilings', [
            'max_chunks' => 10,
            'max_compressed_bytes' => 1000,
            'max_chunk_bytes' => 100,
        ])
        ->getToken($configuration->signer(), $configuration->signingKey())
        ->toString();

    expect(fn () => (new SessionGrantVerifier)->verify(
        $grant,
        $key['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('evicts expired session ids when adding a new issuance', function (): void {
    $session = $this->app['session']->driver();
    $expired = str_repeat('1', 64);
    $active = str_repeat('2', 64);
    $new = str_repeat('3', 64);
    $session->put('reel.issued_sessions', [
        $expired => ['expires_at' => time() - 1, 'issued_at' => time() - 20],
        $active => ['expires_at' => time() + 60, 'issued_at' => time() - 10],
    ]);

    (new IssuedSessionSet)->add($session, $new, time() + 120, 5);

    expect($session->get('reel.issued_sessions'))
        ->not->toHaveKey($expired)
        ->toHaveKeys([$active, $new]);
});

it('evicts the oldest issued session id when the bound is exceeded', function (): void {
    $session = $this->app['session']->driver();
    $oldest = str_repeat('4', 64);
    $newer = str_repeat('5', 64);
    $new = str_repeat('6', 64);
    $session->put('reel.issued_sessions', [
        $oldest => ['expires_at' => time() + 60, 'issued_at' => time() - 20],
        $newer => ['expires_at' => time() + 60, 'issued_at' => time() - 10],
    ]);

    (new IssuedSessionSet)->add($session, $new, time() + 120, 2);

    expect(array_keys($session->get('reel.issued_sessions')))
        ->toBe([$newer, $new])
        ->not->toContain($oldest);
});

it('rejects wrong audience issuer and expired grants', function (): void {
    $clock = new class implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-08-20 12:00:00 UTC');
        }
    };
    $verifier = new SessionGrantVerifier($clock);
    $validTime = new DateTimeImmutable('2026-08-20 11:59:00 UTC');

    expect(fn () => $verifier->verify(reelSignedGrant([
        'audience' => 'wrong-audience',
        'now' => $validTime,
        'expires' => new DateTimeImmutable('2026-08-20 12:01:00 UTC'),
    ]), reelTestKeyPair()['public'], reelGrantContext()))->toThrow(DomainException::class)
        ->and(fn () => $verifier->verify(reelSignedGrant([
            'issuer' => 'wrong-issuer',
            'now' => $validTime,
            'expires' => new DateTimeImmutable('2026-08-20 12:01:00 UTC'),
        ]), reelTestKeyPair()['public'], reelGrantContext()))->toThrow(DomainException::class)
        ->and(fn () => $verifier->verify(reelSignedGrant([
            'now' => new DateTimeImmutable('2026-08-20 11:00:00 UTC'),
            'expires' => new DateTimeImmutable('2026-08-20 11:30:00 UTC'),
        ]), reelTestKeyPair()['public'], reelGrantContext()))->toThrow(DomainException::class);
});

it('rejects a grant bound to another application', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['application_id' => 'other-app']),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects a grant bound to another credential', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['credential_id' => 'sha256:other']),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects a grant bound to a disallowed origin', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['origin' => 'https://attacker.example']),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects a grant bound to another session', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['session_id' => str_repeat('b', 64)]),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects an unsupported protocol version', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['protocol_version' => 2]),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects nonpositive grant ceilings', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['ceilings' => [
            'max_chunks' => 0,
            'max_compressed_bytes' => 1000,
            'max_chunk_bytes' => 100,
        ]]),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects grant ceilings above configured maxima', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['ceilings' => [
            'max_chunks' => 101,
            'max_compressed_bytes' => 1000,
            'max_chunk_bytes' => 100,
        ]]),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects grant ceilings with noninteger values', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['ceilings' => [
            'max_chunks' => '10',
            'max_compressed_bytes' => 1000,
            'max_chunk_bytes' => 100,
        ]]),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects incoherent event and expiration timing', function (): void {
    $expires = new DateTimeImmutable('+5 minutes');

    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant([
            'expires' => $expires,
            'max_event_time' => $expires->getTimestamp(),
        ]),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects a not-before time that differs from issuance', function (): void {
    $issuedAt = new DateTimeImmutable('-1 minute');

    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant([
            'now' => $issuedAt,
            'not_before' => $issuedAt->modify('+30 seconds'),
        ]),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});

it('rejects malformed session identifiers even when the expected binding matches', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant(['session_id' => 'session-id']),
        reelTestKeyPair()['public'],
        reelGrantContext('session-id'),
    ))->toThrow(DomainException::class);
});

it('rejects grants that exceed the configured lifetime', function (): void {
    expect(fn () => (new SessionGrantVerifier)->verify(
        reelSignedGrant([
            'now' => new DateTimeImmutable('-1 second'),
            'expires' => new DateTimeImmutable('+1 hour'),
        ]),
        reelTestKeyPair()['public'],
        reelGrantContext(),
    ))->toThrow(DomainException::class);
});
