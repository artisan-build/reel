<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationCredential;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EnrollmentCodeIssuer
{
    public function issue(Application $application): string
    {
        $code = Str::upper(Str::random(12).'-'.Str::random(12));

        $application->credentials()->create([
            'algorithm' => ApplicationCredential::ALGORITHM,
            'enrollment_code_hash' => Hash::make($code),
            'enrollment_expires_at' => now()->addMinutes(15),
        ]);

        return $code;
    }
}
