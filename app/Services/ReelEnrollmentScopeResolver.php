<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Application;
use ArtisanBuild\BuiltForCloud\BoundCredentialScope;
use ArtisanBuild\BuiltForCloud\Contracts\ResolvesAsymmetricEnrollmentScope;
use Illuminate\Http\Request;

final class ReelEnrollmentScopeResolver implements ResolvesAsymmetricEnrollmentScope
{
    public function resolve(Request $request, string $application): ?BoundCredentialScope
    {
        $resolved = Application::query()
            ->where('public_id', $application)
            ->where('ingest_enabled', true)
            ->first();

        return $resolved instanceof Application ? ReelCredentialScope::for($resolved) : null;
    }
}
