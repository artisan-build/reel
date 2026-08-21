<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use DateTimeInterface;
use DomainException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\HasClaim;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;
use Throwable;

final readonly class SessionGrantVerifier
{
    public function __construct(private ?ClockInterface $clock = null) {}

    public function verify(string $grant, string $publicKey, SessionGrantContext $context): Plain
    {
        $signer = new Sha256;
        $key = InMemory::plainText($publicKey);
        $configuration = Configuration::forAsymmetricSigner($signer, $key, $key);

        try {
            $token = $configuration->parser()->parse($grant);

            if (! $token instanceof Plain
                || $token->headers()->get('typ') !== SessionGrant::TYPE) {
                throw new DomainException('The Reel grant type is invalid.');
            }

            $valid = $configuration->validator()->validate($token,
                new SignedWith($signer, $key),
                new IssuedBy(SessionGrant::ISSUER),
                new PermittedFor(SessionGrant::AUDIENCE),
                new StrictValidAt($this->clock ?? new SystemClock),
                new HasClaim('application_id'),
                new HasClaim('credential_id'),
                new HasClaim('session_id'),
                new HasClaim('origin'),
                new HasClaim('protocol_version'),
                new HasClaim('max_event_time'),
                new HasClaim('ceilings'),
                new HasClaim('application_user_id'),
                new HasClaim('release_id'),
            );

            if (! $valid) {
                throw new DomainException('The Reel grant failed validation.');
            }

            $this->assertClaims($token, $context);

            return $token;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException('The Reel grant is invalid.', previous: $exception);
        }
    }

    private function assertClaims(Plain $token, SessionGrantContext $context): void
    {
        $claims = $token->claims();
        $ceilings = $claims->get('ceilings');
        $issuedAt = $claims->get('iat');
        $notBefore = $claims->get('nbf');
        $expiresAt = $claims->get('exp');
        $maxEventTime = $claims->get('max_event_time');
        $grantId = $claims->get('jti');
        $sessionId = $claims->get('session_id');
        $applicationUserId = $claims->get('application_user_id');
        $releaseId = $claims->get('release_id');

        if ($claims->get('application_id') !== $context->applicationId
            || $claims->get('credential_id') !== $context->credentialId
            || $sessionId !== $context->sessionId
            || preg_match('/^[a-f0-9]{64}$/', $sessionId) !== 1
            || ! in_array($claims->get('origin'), $context->allowedOrigins, true)
            || $claims->get('protocol_version') !== Envelope::VERSION
            || ! is_string($grantId)
            || $grantId === ''
            || strlen($grantId) > 128) {
            throw new DomainException('The Reel grant binding is invalid.');
        }

        if (! $this->isOptionalMetadata($applicationUserId, 128)
            || ! $this->isOptionalMetadata($releaseId, 255)) {
            throw new DomainException('The Reel grant metadata is invalid.');
        }

        if (! $issuedAt instanceof DateTimeInterface
            || ! $notBefore instanceof DateTimeInterface
            || ! $expiresAt instanceof DateTimeInterface
            || ! is_int($maxEventTime)
            || $notBefore->getTimestamp() !== $issuedAt->getTimestamp()
            || $maxEventTime < $issuedAt->getTimestamp()
            || $maxEventTime >= $expiresAt->getTimestamp()
            || $expiresAt->getTimestamp() - $issuedAt->getTimestamp() > $context->maximumLifetimeSeconds) {
            throw new DomainException('The Reel grant timing is invalid.');
        }

        if (! is_array($ceilings)) {
            throw new DomainException('The Reel grant ceilings are invalid.');
        }

        $ceilingNames = array_keys($ceilings);
        $expectedNames = array_keys($context->maximumCeilings);
        sort($ceilingNames);
        sort($expectedNames);

        if ($ceilingNames !== $expectedNames) {
            throw new DomainException('The Reel grant ceilings are invalid.');
        }

        foreach ($context->maximumCeilings as $name => $maximum) {
            $value = $ceilings[$name] ?? null;

            if (! is_int($value) || $value <= 0 || $value > $maximum) {
                throw new DomainException('The Reel grant ceilings are invalid.');
            }
        }
    }

    private function isOptionalMetadata(mixed $value, int $maximumBytes): bool
    {
        return $value === null
            || (is_string($value)
                && $value !== ''
                && strlen($value) <= $maximumBytes
                && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1);
    }
}
