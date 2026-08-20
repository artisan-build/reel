<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\KeyMaterial;
use ArtisanBuild\ReelClient\SessionGrant;
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
    $now = $overrides['now'] ?? new DateTimeImmutable('-1 second');
    $expires = $overrides['expires'] ?? new DateTimeImmutable('+5 minutes');
    $configuration = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::plainText($key['private']),
        InMemory::plainText($key['public']),
    );

    return $configuration->builder()
        ->withHeader('typ', $overrides['typ'] ?? SessionGrant::TYPE)
        ->issuedBy($overrides['issuer'] ?? SessionGrant::ISSUER)
        ->permittedFor($overrides['audience'] ?? SessionGrant::AUDIENCE)
        ->identifiedBy('grant-id')
        ->issuedAt($now)
        ->canOnlyBeUsedAfter($now)
        ->expiresAt($expires)
        ->withClaim('application_id', 'app-id')
        ->withClaim('credential_id', $key['credential_id'])
        ->withClaim('session_id', 'session-id')
        ->withClaim('origin', 'https://host.example')
        ->withClaim('protocol_version', 1)
        ->withClaim('max_event_time', $expires->getTimestamp() - 60)
        ->withClaim('ceilings', ['max_chunks' => 10, 'max_compressed_bytes' => 1000, 'max_chunk_bytes' => 100])
        ->getToken($configuration->signer(), $configuration->signingKey())
        ->toString();
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

    $token = (new SessionGrantVerifier)->verify($response->json('grant'), reelTestKeyPair()['public']);

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
        expect($entry['expires_at'])->toBeGreaterThan(time());
    }
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
    expect(fn () => $verifier->verify($none, reelTestKeyPair()['public']))->toThrow(DomainException::class)
        ->and(fn () => $verifier->verify($swapped, reelTestKeyPair()['public']))->toThrow(DomainException::class);
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
    ]), reelTestKeyPair()['public']))->toThrow(DomainException::class)
        ->and(fn () => $verifier->verify(reelSignedGrant([
            'issuer' => 'wrong-issuer',
            'now' => $validTime,
            'expires' => new DateTimeImmutable('2026-08-20 12:01:00 UTC'),
        ]), reelTestKeyPair()['public']))->toThrow(DomainException::class)
        ->and(fn () => $verifier->verify(reelSignedGrant([
            'now' => new DateTimeImmutable('2026-08-20 11:00:00 UTC'),
            'expires' => new DateTimeImmutable('2026-08-20 11:30:00 UTC'),
        ]), reelTestKeyPair()['public']))->toThrow(DomainException::class);
});
