<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Application;
use ArtisanBuild\BuiltForCloud\BoundCredentialScope;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\ReelClient\SessionGrant;

final class ReelCredentialScope
{
    public const string PURPOSE = 'reel.application.signing';

    public const string INSTALLATION = 'reel';

    public static function for(Application $application): BoundCredentialScope
    {
        return new BoundCredentialScope(
            appPurpose: self::PURPOSE,
            subject: new Subject(SubjectType::Installation, 'application:'.$application->public_id),
            installation: self::INSTALLATION,
            application: $application->public_id,
            audience: SessionGrant::AUDIENCE,
        );
    }
}
