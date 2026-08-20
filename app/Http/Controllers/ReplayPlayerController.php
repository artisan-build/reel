<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ReplayView;
use App\Services\ReplayPayloadReader;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;
use RuntimeException;

class ReplayPlayerController extends Controller
{
    public function __invoke(
        Request $request,
        Application $application,
        string $recordingSession,
        ReplayPayloadReader $reader,
    ): Response {
        $session = $application->recordingSessions()
            ->where('session_id', $recordingSession)
            ->firstOrFail();

        $channel = $request->query('channel');
        abort_unless(is_string($channel) && preg_match('/^[a-f0-9]{96}$/', $channel) === 1, 404);

        ReplayView::query()->create([
            'user_id' => $request->user()->getAuthIdentifier(),
            'application_id' => $application->getKey(),
            'recording_session_id' => $session->getKey(),
            'viewed_at' => now(),
        ]);

        $session->setRelation('application', $application);
        $payload = $reader->read($session);
        $scriptNonce = bin2hex(random_bytes(24));
        $playerRuntime = $this->readFile(resource_path('js/replay-player.js'));
        $messageRuntime = $this->readFile(public_path('build/assets/replay-message-channel.js'));
        $rrwebRuntime = $payload->diagnostic === null
            ? $this->readFile(base_path('packages/reel-client/resources/vendor/rrweb.umd.min.cjs'))
            : '';
        $start = $request->integer('start');
        $configuration = $this->encodeJson([
            'channelNonce' => $channel,
            'parentOrigin' => $request->getSchemeAndHttpHost(),
            'start' => max(0, $start),
            'diagnostic' => $payload->diagnostic,
        ]);
        $events = $this->encodeJson($payload->events);
        $csp = "default-src 'none'; script-src 'nonce-{$scriptNonce}'; style-src 'unsafe-inline'";

        return response()->view('replay-player', compact(
            'configuration',
            'events',
            'messageRuntime',
            'playerRuntime',
            'rrwebRuntime',
            'scriptNonce',
        ))->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => $csp,
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('The replay player runtime is unavailable.');
        }

        return $contents;
    }

    /** @throws JsonException */
    private function encodeJson(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
    }
}
