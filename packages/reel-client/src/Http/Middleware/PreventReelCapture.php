<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Http\Middleware;

use ArtisanBuild\ReelClient\CapturePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreventReelCapture
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('reel.hidden', true);

        if (CapturePolicy::isDocumentRequest($request) && $request->hasSession()) {
            $request->session()->put('reel.current_page_hidden', true);
        }

        $response = $next($request);
        $response->headers->set(CapturePolicy::RESPONSE_HEADER, 'hidden');

        return $response;
    }
}
