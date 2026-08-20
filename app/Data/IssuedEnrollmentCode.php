<?php

namespace App\Data;

final readonly class IssuedEnrollmentCode
{
    public function __construct(
        public string $code,
        public int $expiresAt,
    ) {}
}
