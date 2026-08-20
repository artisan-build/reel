<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use RuntimeException;
use SensitiveParameter;

final class KeyMaterial
{
    /**
     * @return array{private: string, public: string, credential_id: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('Unable to generate the Reel signing key.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['key'])) {
            throw new RuntimeException('Unable to derive the Reel public key.');
        }

        return [
            'private' => $privateKey,
            'public' => $details['key'],
            'credential_id' => self::credentialId($details['key']),
        ];
    }

    public static function encodePrivateKey(#[SensitiveParameter] string $privateKey): string
    {
        return 'base64:'.base64_encode($privateKey);
    }

    public static function decodePrivateKey(#[SensitiveParameter] string $encoded): string
    {
        if (! str_starts_with($encoded, 'base64:')) {
            throw new RuntimeException('REEL_PRIVATE_KEY must contain a base64-encoded PEM key.');
        }

        $decoded = base64_decode(substr($encoded, 7), true);

        if ($decoded === false || openssl_pkey_get_private($decoded) === false) {
            throw new RuntimeException('REEL_PRIVATE_KEY is not a valid private key.');
        }

        return $decoded;
    }

    public static function publicKeyFromPrivate(#[SensitiveParameter] string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['key'])) {
            throw new RuntimeException('Unable to derive the Reel public key.');
        }

        return $details['key'];
    }

    public static function credentialId(string $publicKey): string
    {
        return 'sha256:'.hash('sha256', trim($publicKey));
    }
}
