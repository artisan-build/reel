<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ProvisionedApplication
{
    public function __construct(
        public string $applicationId,
        public string $enrollmentCode,
        public bool $created,
    ) {}

    /** @return array{application_id: string, enrollment_code: string, enrollment_path: string} */
    public function response(): array
    {
        return [
            'application_id' => $this->applicationId,
            'enrollment_code' => $this->enrollmentCode,
            'enrollment_path' => '/bfc/asymmetric-enrollments/'.$this->applicationId,
        ];
    }
}
