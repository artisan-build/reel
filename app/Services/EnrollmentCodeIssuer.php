<?php

namespace App\Services;

use App\Data\IssuedEnrollmentCode;
use App\Models\Application;
use App\Models\ApplicationCredential;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EnrollmentCodeIssuer
{
    public function issue(Application $application): IssuedEnrollmentCode
    {
        $code = Str::upper(Str::random(12).'-'.Str::random(12));
        $expiresAt = now()->addMinutes(15);

        $application->credentials()->create([
            'algorithm' => ApplicationCredential::ALGORITHM,
            'enrollment_code_hash' => Hash::make($code),
            'enrollment_expires_at' => $expiresAt,
        ]);

        return new IssuedEnrollmentCode($code, $expiresAt->getTimestamp());
    }
}
