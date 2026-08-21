<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Http\Middleware;

use ArtisanBuild\ReelClient\CapturePolicy;
use ArtisanBuild\ReelClient\Correlation;
use ArtisanBuild\ReelClient\IssuedSessionSet;
use ArtisanBuild\ReelClient\Reel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final readonly class CorrelateReelRequest
{
    public function __construct(
        private IssuedSessionSet $issuedSessions,
        private Reel $reel,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        Context::forget('reel');

        if (! CapturePolicy::isHidden($request->route())
            && $request->hasSession()) {
            $this->bind($request);
        }

        $response = $next($request);

        if ($request->attributes->get(Correlation::BINDING_ATTRIBUTE) === 'host_bound'
            && $response->getStatusCode() >= 500) {
            $response->headers->set(Correlation::SERVER_ERROR_HEADER, '1');
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        Context::forget('reel');
    }

    private function bind(Request $request): void
    {
        $claim = $request->attributes->get(Correlation::CLAIM_ATTRIBUTE);
        $request->attributes->remove(Correlation::CLAIM_ATTRIBUTE);

        if (is_string($claim)) {
            if (strlen($claim) !== 64 || preg_match('/^[a-f0-9]{64}$/', $claim) !== 1) {
                $request->attributes->set(Correlation::REJECTION_ATTRIBUTE, 'invalid_format');

                return;
            }

            $accepted = $this->issuedSessions->accept($request->session(), $claim, $request->getPathInfo());

            if ($accepted !== null) {
                $this->apply($request, $claim, 'host_bound');
            } else {
                $request->attributes->set(Correlation::REJECTION_ATTRIBUTE, 'not_issued_or_expired');
            }

            return;
        }

        if (! $this->isTopLevelGet($request)) {
            return;
        }

        $candidates = $this->issuedSessions->recent(
            $request->session(),
            (int) config('reel.correlation.approximate_window_seconds'),
        );

        if (count($candidates) === 1) {
            $sessionId = (string) array_key_first($candidates);
            $this->issuedSessions->accept($request->session(), $sessionId, $request->getPathInfo());
            $this->apply($request, $sessionId, 'approximate');

            return;
        }

        if (count($candidates) > 1) {
            $this->applyAmbiguous($request, $candidates);
        }
    }

    private function apply(Request $request, string $sessionId, string $binding): void
    {
        $request->attributes->set(Correlation::BINDING_ATTRIBUTE, $binding);
        $mode = $this->exportMode();

        if ($mode === 'off') {
            return;
        }

        $payload = [
            'session_id' => $sessionId,
            'binding' => $binding,
        ];

        if ($mode === 'session_id_and_url') {
            $payload['url'] = $this->reel->sessionUrl($sessionId);
        }

        Context::add('reel', $payload);
    }

    /** @param array<string, array{expires_at: int, issued_at: int, last_active_at: int, path: string|null}> $candidates */
    private function applyAmbiguous(Request $request, array $candidates): void
    {
        $request->attributes->set(Correlation::BINDING_ATTRIBUTE, 'ambiguous');
        $mode = $this->exportMode();

        if ($mode === 'off') {
            return;
        }

        $payload = [
            'binding' => 'ambiguous',
            'candidate_count' => count($candidates),
        ];

        if ($mode === 'session_id_and_url') {
            $issuedAt = array_column($candidates, 'issued_at');
            $payload['candidates_url'] = $this->reel->candidateSessionsUrl(
                min($issuedAt),
                max($issuedAt),
                $request->getPathInfo(),
            );
        }

        Context::add('reel', $payload);
    }

    private function exportMode(): string
    {
        $mode = (string) config('reel.correlation.context_export', 'off');

        return in_array($mode, Correlation::EXPORT_MODES, true) ? $mode : 'off';
    }

    private function isTopLevelGet(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()) {
            return false;
        }

        $destination = $request->headers->get('Sec-Fetch-Dest');
        $mode = $request->headers->get('Sec-Fetch-Mode');

        return $destination === 'document'
            || $mode === 'navigate'
            || ($destination === null && $mode === null && $request->acceptsHtml());
    }
}
