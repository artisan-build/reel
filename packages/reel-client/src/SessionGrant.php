<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use SensitiveParameter;

final class SessionGrant
{
    public const string TYPE = 'reel-session+jwt';

    public const string ALGORITHM = 'RS256';

    public const string ISSUER = 'artisan-build/reel-client';

    public const string AUDIENCE = 'artisan-build/reel-ingest';

    /**
     * @param  array{max_chunks: int, max_compressed_bytes: int, max_chunk_bytes: int}  $ceilings
     */
    public static function mint(
        #[SensitiveParameter] string $privateKey,
        string $applicationId,
        string $sessionId,
        string $origin,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $maxEventTime,
        array $ceilings,
        ?string $grantId = null,
        ?string $applicationUserId = null,
        ?string $releaseId = null,
    ): string {
        $publicKey = KeyMaterial::publicKeyFromPrivate($privateKey);
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($privateKey),
            InMemory::plainText($publicKey),
        );

        return $configuration->builder()
            ->withHeader('typ', self::TYPE)
            ->issuedBy(self::ISSUER)
            ->permittedFor(self::AUDIENCE)
            ->identifiedBy($grantId ?? bin2hex(random_bytes(16)))
            ->issuedAt($issuedAt)
            ->canOnlyBeUsedAfter($issuedAt)
            ->expiresAt($expiresAt)
            ->withClaim('application_id', $applicationId)
            ->withClaim('credential_id', KeyMaterial::credentialId($publicKey))
            ->withClaim('session_id', $sessionId)
            ->withClaim('origin', $origin)
            ->withClaim('protocol_version', Envelope::VERSION)
            ->withClaim('max_event_time', $maxEventTime->getTimestamp())
            ->withClaim('ceilings', $ceilings)
            ->withClaim('application_user_id', $applicationUserId)
            ->withClaim('release_id', $releaseId)
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }
}
