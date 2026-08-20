<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Http\Controllers;

use Illuminate\Http\Response;

final class AssetController
{
    public function rrweb(): Response
    {
        return $this->asset('vendor/rrweb.umd.min.cjs', 'application/javascript; charset=UTF-8');
    }

    public function recorder(): Response
    {
        return $this->asset('js/reel-recorder.js', 'application/javascript; charset=UTF-8');
    }

    private function asset(string $path, string $contentType): Response
    {
        $contents = file_get_contents(__DIR__.'/../../../resources/'.$path);
        abort_if($contents === false, 404);

        return response($contents)->withHeaders([
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
