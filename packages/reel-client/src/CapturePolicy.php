<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use ArtisanBuild\ReelClient\Http\Middleware\PreventReelCapture;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final class CapturePolicy
{
    public const string RESPONSE_HEADER = 'X-Reel-Capture';

    public static function isHidden(?Route $route): bool
    {
        if (! $route instanceof Route) {
            return false;
        }

        if ($route->getMetadata('reel.hidden') === true) {
            return true;
        }

        $declared = [
            ...$route->middleware(),
            ...(array) $route->getAction('excluded_middleware'),
        ];

        return collect($declared)->contains(
            fn (string $middleware): bool => $middleware === 'reel.hidden'
                || $middleware === PreventReelCapture::class,
        );
    }

    public static function isDocumentRequest(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept'));

        return ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && ($request->header('Sec-Fetch-Dest') === 'document' || str_contains($accept, 'text/html'));
    }
}
