<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Http\Middleware;

use ArtisanBuild\ReelClient\Correlation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RedactReelHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $claim = $request->headers->get(Correlation::REQUEST_HEADER);

        if (is_string($claim)) {
            $request->attributes->set(Correlation::CLAIM_ATTRIBUTE, $claim);
        }

        $request->headers->remove(Correlation::REQUEST_HEADER);

        return $next($request);
    }
}
