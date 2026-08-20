<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Http\Middleware;

use ArtisanBuild\ReelClient\CapturePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RememberCapturePolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $hidden = CapturePolicy::isHidden($request->route());

        if (CapturePolicy::isDocumentRequest($request) && $request->hasSession()) {
            $request->session()->put('reel.current_page_hidden', $hidden);
            $request->session()->save();
        }

        if ($hidden) {
            $response->headers->set(CapturePolicy::RESPONSE_HEADER, 'hidden');
        }

        return $response;
    }
}
