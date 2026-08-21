<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

final class Correlation
{
    public const string REQUEST_HEADER = 'X-Reel-Session';

    public const string SERVER_ERROR_HEADER = 'X-Reel-Server-Error';

    public const string CLAIM_ATTRIBUTE = 'reel.correlation.claim';

    public const string BINDING_ATTRIBUTE = 'reel.correlation.binding';

    public const string REJECTION_ATTRIBUTE = 'reel.correlation.rejection';

    /** @var list<string> */
    public const array EXPORT_MODES = ['off', 'session_id', 'session_id_and_url'];
}
