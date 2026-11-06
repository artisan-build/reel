<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;
use SensitiveParameter;

final readonly class EnrollmentCodeCipher
{
    public function __construct(private Encrypter $encrypter) {}

    public function encrypt(#[SensitiveParameter] string $code): string
    {
        return $this->encrypter->encrypt($code, false);
    }

    public function decrypt(string $ciphertext): string
    {
        $code = $this->encrypter->decrypt($ciphertext, false);

        if (! is_string($code)) {
            throw new RuntimeException('The enrollment code could not be decrypted.');
        }

        return $code;
    }
}
