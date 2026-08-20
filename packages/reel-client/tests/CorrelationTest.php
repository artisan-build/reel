<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Correlation;
use ArtisanBuild\ReelClient\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

uses(TestCase::class);

final class ReelCorrelationTestException extends RuntimeException
{
    /** @var array<string, mixed>|null */
    public static ?array $reportedContext = null;

    public function report(): bool
    {
        $context = Context::get('reel');
        self::$reportedContext = is_array($context) ? $context : null;

        return true;
    }
}

/** @return array{expires_at: int, issued_at: int, last_active_at: int, path: string} */
function reelIssuedEntry(int $issuedAt = 0, int $expiresAt = 0, string $path = '/checkout'): array
{
    $issuedAt = $issuedAt === 0 ? time() - 5 : $issuedAt;

    return [
        'expires_at' => $expiresAt === 0 ? time() + 300 : $expiresAt,
        'issued_at' => $issuedAt,
        'last_active_at' => $issuedAt,
        'path' => $path,
    ];
}

beforeEach(function (): void {
    config()->set([
        'reel.url' => 'https://reel.example',
        'reel.application_id' => 'application-id',
        'reel.correlation.context_export' => 'off',
        'reel.correlation.approximate_window_seconds' => 300,
    ]);

    Route::middleware('web')->get('/reel-test/correlation', function (Request $request) {
        return response()->json([
            'reel' => Context::get('reel'),
            'binding' => $request->attributes->get(Correlation::BINDING_ATTRIBUTE),
            'rejection' => $request->attributes->get(Correlation::REJECTION_ATTRIBUTE),
            'request_headers' => $request->headers->all(),
        ]);
    });
    Route::middleware('web')->get('/reel-test/correlation/failure', fn () => response('failed', 500));
    Route::middleware(['web', 'reel.hidden'])->get('/reel-test/correlation/hidden', function () {
        return response()->json(['reel' => Context::get('reel')], 500);
    });
    Route::middleware('web')->get('/reel-test/correlation/exception', function (): never {
        throw new ReelCorrelationTestException('private exception message');
    });
});

it('rejects a well-formed session id that was never issued to this visitor', function (): void {
    $unissued = str_repeat('a', 64);

    $this->withSession(['reel.issued_sessions' => [str_repeat('b', 64) => reelIssuedEntry()]])
        ->getJson('/reel-test/correlation', [Correlation::REQUEST_HEADER => $unissued])
        ->assertOk()
        ->assertJsonPath('binding', null)
        ->assertJsonPath('rejection', 'not_issued_or_expired')
        ->assertJsonPath('reel', null);
});

it('rejects expired ids and ids issued to a different visitor session', function (string $case): void {
    $claimed = str_repeat('c', 64);
    $issued = $case === 'expired'
        ? [$claimed => reelIssuedEntry(expiresAt: time() - 1)]
        : [str_repeat('d', 64) => reelIssuedEntry()];

    $this->withSession(['reel.issued_sessions' => $issued])
        ->getJson('/reel-test/correlation', [Correlation::REQUEST_HEADER => $claimed])
        ->assertOk()
        ->assertJsonPath('binding', null)
        ->assertJsonPath('rejection', 'not_issued_or_expired');
})->with(['expired', 'different visitor']);

it('requires a full exact session id match', function (): void {
    $issued = str_repeat('e', 64);
    $nearMiss = str_repeat('e', 63).'f';

    $this->withSession(['reel.issued_sessions' => [$issued => reelIssuedEntry()]])
        ->getJson('/reel-test/correlation', [Correlation::REQUEST_HEADER => $nearMiss])
        ->assertOk()
        ->assertJsonPath('binding', null)
        ->assertJsonPath('rejection', 'not_issued_or_expired');
});

it('rejects malformed session ids with a specific reason', function (): void {
    $this->getJson('/reel-test/correlation', [Correlation::REQUEST_HEADER => str_repeat('g', 64)])
        ->assertOk()
        ->assertJsonPath('binding', null)
        ->assertJsonPath('rejection', 'invalid_format')
        ->assertJsonPath('reel', null);
});

it('pins the exact Context and redacted request-header payload for every export mode', function (
    string $mode,
    ?array $expected,
): void {
    $sessionId = str_repeat('a', 64);
    config()->set('reel.correlation.context_export', $mode);

    $response = $this->withSession(['reel.issued_sessions' => [$sessionId => reelIssuedEntry()]])
        ->getJson('/reel-test/correlation', [Correlation::REQUEST_HEADER => $sessionId])
        ->assertOk()
        ->assertJsonPath('binding', 'host_bound')
        ->assertJsonPath('reel', $expected);

    $headers = $response->json('request_headers');
    expect($headers)->not->toHaveKey('x-reel-session')
        ->and(json_encode($headers, JSON_THROW_ON_ERROR))->not->toContain($sessionId, 'grant');
})->with([
    'off' => ['off', null],
    'session id' => ['session_id', [
        'session_id' => str_repeat('a', 64),
        'binding' => 'host_bound',
    ]],
    'session id and url' => ['session_id_and_url', [
        'session_id' => str_repeat('a', 64),
        'binding' => 'host_bound',
        'url' => 'https://reel.example/applications/application-id/sessions/'.str_repeat('a', 64),
    ]],
]);

it('exports the same provider-neutral payload for stock Nightwatch and Hone transports', function (string $transport): void {
    $sessionId = str_repeat('7', 64);
    config()->set('reel.correlation.context_export', 'session_id');

    $payload = $this->withSession(['reel.issued_sessions' => [$sessionId => reelIssuedEntry()]])
        ->getJson('/reel-test/correlation?transport='.$transport, [Correlation::REQUEST_HEADER => $sessionId])
        ->assertOk()
        ->json('reel');

    expect($payload)->toBe([
        'session_id' => $sessionId,
        'binding' => 'host_bound',
    ]);
})->with(['nightwatch-saas', 'hone']);

it('adds and clears Context across sequential success requests in one process', function (): void {
    $sessionId = str_repeat('8', 64);
    config()->set('reel.correlation.context_export', 'session_id');

    $this->withSession(['reel.issued_sessions' => [$sessionId => reelIssuedEntry()]])
        ->getJson('/reel-test/correlation', [Correlation::REQUEST_HEADER => $sessionId])
        ->assertJsonPath('reel.session_id', $sessionId);

    $this->getJson('/reel-test/correlation')
        ->assertOk()
        ->assertJsonPath('reel', null)
        ->assertJsonPath('binding', null);

    expect(Context::has('reel'))->toBeFalse();
});

it('purges stale worker Context before an uncorrelated request', function (): void {
    Context::add('reel', [
        'session_id' => str_repeat('f', 64),
        'binding' => 'host_bound',
    ]);

    $this->getJson('/reel-test/correlation')
        ->assertOk()
        ->assertJsonPath('reel', null)
        ->assertJsonPath('binding', null);

    expect(Context::has('reel'))->toBeFalse();
});

it('keeps Context through exception reporting and clears it after the request', function (): void {
    $sessionId = str_repeat('9', 64);
    config()->set('reel.correlation.context_export', 'session_id');
    ReelCorrelationTestException::$reportedContext = null;

    $this->withSession(['reel.issued_sessions' => [$sessionId => reelIssuedEntry()]])
        ->getJson('/reel-test/correlation/exception', [Correlation::REQUEST_HEADER => $sessionId])
        ->assertServerError()
        ->assertHeader(Correlation::SERVER_ERROR_HEADER, '1');

    expect(ReelCorrelationTestException::$reportedContext)->toBe([
        'session_id' => $sessionId,
        'binding' => 'host_bound',
    ])->and(Context::has('reel'))->toBeFalse();
});

it('signals a sanitized server marker only for an exact accepted binding', function (bool $issued): void {
    $sessionId = str_repeat('6', 64);
    $sessions = $issued ? [$sessionId => reelIssuedEntry()] : [];
    $response = $this->withSession(['reel.issued_sessions' => $sessions])
        ->get('/reel-test/correlation/failure', [Correlation::REQUEST_HEADER => $sessionId])
        ->assertServerError();

    if ($issued) {
        $response->assertHeader(Correlation::SERVER_ERROR_HEADER, '1');
    } else {
        $response->assertHeaderMissing(Correlation::SERVER_ERROR_HEADER);
    }
})->with(['issued' => true, 'unissued' => false]);

it('suppresses Context and server markers on hidden routes', function (): void {
    $sessionId = str_repeat('2', 64);
    config()->set('reel.correlation.context_export', 'session_id');

    $this->withSession(['reel.issued_sessions' => [$sessionId => reelIssuedEntry()]])
        ->getJson('/reel-test/correlation/hidden', [Correlation::REQUEST_HEADER => $sessionId])
        ->assertServerError()
        ->assertJsonPath('reel', null)
        ->assertHeaderMissing(Correlation::SERVER_ERROR_HEADER);
});

it('labels a sole top-level navigation candidate as approximate', function (): void {
    $sessionId = str_repeat('5', 64);
    config()->set('reel.correlation.context_export', 'session_id');

    $this->withSession(['reel.issued_sessions' => [$sessionId => reelIssuedEntry()]])
        ->get('/reel-test/correlation', ['Accept' => 'text/html', 'Sec-Fetch-Mode' => 'navigate'])
        ->assertOk()
        ->assertJsonPath('reel', [
            'session_id' => $sessionId,
            'binding' => 'approximate',
        ])
        ->assertJsonPath('binding', 'approximate');

    expect(session('reel.issued_sessions')[$sessionId]['path'])->toBe('/reel-test/correlation')
        ->and(session('reel.issued_sessions')[$sessionId]['last_active_at'])->toBeGreaterThanOrEqual(time() - 1);
});

it('exports an ambiguity filter without selecting one of multiple plausible tabs', function (): void {
    $first = str_repeat('3', 64);
    $second = str_repeat('4', 64);
    config()->set('reel.correlation.context_export', 'session_id_and_url');

    $context = $this->withSession(['reel.issued_sessions' => [
        $first => reelIssuedEntry(time() - 10, path: '/first-tab'),
        $second => reelIssuedEntry(time() - 5, path: '/second-tab'),
    ]])->get('/reel-test/correlation', ['Accept' => 'text/html', 'Sec-Fetch-Dest' => 'document'])
        ->assertOk()
        ->assertJsonPath('binding', 'ambiguous')
        ->json('reel');

    expect(array_keys($context))->toBe(['binding', 'candidate_count', 'candidates_url'])
        ->and($context['binding'])->toBe('ambiguous')
        ->and($context['candidate_count'])->toBe(2)
        ->and($context)->not->toHaveKey('session_id', 'url')
        ->and($context['candidates_url'])->toContain(
            'https://reel.example/applications/application-id/sessions?',
            'startedFrom=',
            'startedTo=',
            'path=%2Freel-test%2Fcorrelation',
        )
        ->not->toContain($first, $second);
});

it('keeps Nightwatch and Hone optional and unmodified', function (): void {
    $root = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $package = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $encoded = json_encode([$root, $package], JSON_THROW_ON_ERROR);

    expect(array_keys($root['require']))->not->toContain('laravel/nightwatch', 'laravel/hone')
        ->and(array_keys($package['require']))->not->toContain('laravel/nightwatch', 'laravel/hone')
        ->and($root['repositories'])->toBe([[
            'type' => 'path',
            'url' => 'packages/reel-client',
            'options' => ['symlink' => true],
        ]])
        ->and($encoded)->not->toContain('composer-patches', 'cweagans/composer-patches', 'nightwatch-fork');
});
