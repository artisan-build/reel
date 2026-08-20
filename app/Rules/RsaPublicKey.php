<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use OpenSSLAsymmetricKey;

class RsaPublicKey implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)
            || preg_match('/-----BEGIN [^-]*PRIVATE KEY-----/', $value) === 1
            || ! str_contains($value, '-----BEGIN PUBLIC KEY-----')
        ) {
            $fail('The :attribute must contain an RSA public key and never a private key.');

            return;
        }

        $key = openssl_pkey_get_public($value);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            $fail('The :attribute must contain a valid RSA public key.');

            return;
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false
            || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || $details['bits'] < 2048
        ) {
            $fail('The :attribute must contain an RSA public key of at least 2048 bits.');
        }
    }
}
