<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

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

final class SessionGrantVerifier
{
    public function __construct(private readonly ?ClockInterface $clock = null) {}

    public function verify(string $grant, string $publicKey): Plain
    {
        $signer = new Sha256;
        $key = InMemory::plainText($publicKey);
        $configuration = Configuration::forAsymmetricSigner($signer, $key, $key);

        try {
            $token = $configuration->parser()->parse($grant);

            if (! $token instanceof Plain
                || $token->headers()->get('typ') !== SessionGrant::TYPE
                || $token->headers()->get('alg') !== SessionGrant::ALGORITHM) {
                throw new DomainException('The Reel grant type or algorithm is invalid.');
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
            );

            if (! $valid) {
                throw new DomainException('The Reel grant failed validation.');
            }

            return $token;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException('The Reel grant is invalid.', previous: $exception);
        }
    }
}
