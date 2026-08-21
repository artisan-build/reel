<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Correlation;
use ArtisanBuild\ReelClient\Http\Middleware\CorrelateReelRequest;
use ArtisanBuild\ReelClient\Http\Middleware\RedactReelHeaders;
use ArtisanBuild\ReelClient\IssuedSessionSet;
use ArtisanBuild\ReelClient\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Compatibility;
use Laravel\Nightwatch\Sensors\RequestSensor;
use Laravel\Nightwatch\State\RequestState;
use Laravel\Nightwatch\UserProvider;

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

/** @return array<string, mixed> */
function reelStockNightwatchPayload(Request $request): array
{
    Compatibility::boot(app());
    $user = new UserProvider(
        withAuth: fn (callable $callback): string => '',
        userDetailsResolverResolver: fn (): null => null,
        reportResolver: fn (): Closure => fn (Throwable $exception, bool $handled): null => null,
    );
    $state = new RequestState(
        timestamp: microtime(true),
        trace: 'nightwatch-contract-trace',
        id: 'nightwatch-contract-request',
        deploy: 'test',
        server: 'test',
        currentExecutionStageStartedAtMicrotime: microtime(true),
        user: $user,
    );
    [, $serialize] = (new RequestSensor(
        requestState: $state,
        capturePayload: false,
        redactPayloadFields: [],
        redactHeaders: [],
    ))($request, response('', 200));

    return $serialize();
}

beforeEach(function (): void {
    config()->set([
        'reel.url' => 'https://reel.example',
        'reel.application_id' => 'application-id',
        'reel.correlation.context_export' => 'off',
        'reel.correlation.approximate_window_seconds' => 300,
    ]);

    Route::middleware('web')->get('/reel-test/correlation', fn (Request $request) => response()->json([
        'reel' => Context::get('reel'),
        'binding' => $request->attributes->get(Correlation::BINDING_ATTRIBUTE),
        'rejection' => $request->attributes->get(Correlation::REJECTION_ATTRIBUTE),
        'request_headers' => $request->headers->all(),
    ]));
    Route::middleware('web')->get('/reel-test/correlation/failure', fn () => response('failed', 500));
    Route::middleware('web')->get(
        '/reel-test/correlation/nightwatch',
        fn (Request $request) => response()->json(reelStockNightwatchPayload($request)),
    );
    Route::middleware(['web', 'reel.hidden'])->get('/reel-test/correlation/hidden', fn () => response()->json(['reel' => Context::get('reel')], 500));
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

it('rejects an expired id from this visitor session', function (): void {
    $claimed = str_repeat('c', 64);

    $this->withSession(['reel.issued_sessions' => [
        $claimed => reelIssuedEntry(expiresAt: time() - 1),
    ]])
        ->getJson('/reel-test/correlation', [Correlation::REQUEST_HEADER => $claimed])
        ->assertOk()
        ->assertJsonPath('binding', null)
        ->assertJsonPath('rejection', 'not_issued_or_expired');
});

it('rejects an id issued to a genuinely different visitor session', function (): void {
    $sessionId = str_repeat('d', 64);
    $handler = new ArraySessionHandler(120);
    $visitorA = new Store('reel-session', $handler, 'visitor-a-session');
    $visitorB = new Store('reel-session', $handler, 'visitor-b-session');
    $visitorA->start();
    $visitorB->start();
    app(IssuedSessionSet::class)->add($visitorA, $sessionId, time() + 300, 8, '/visitor-a');
    $visitorA->save();
    expect($visitorA->getId())->not->toBe($visitorB->getId())
        ->and($visitorA->get('reel.issued_sessions'))->toHaveKey($sessionId)
        ->and($visitorB->missing('reel.issued_sessions'))->toBeTrue();

    $request = Request::create('/orders', 'GET', server: [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REEL_SESSION' => $sessionId,
    ]);
    $request->setLaravelSession($visitorB);
    $correlate = app(CorrelateReelRequest::class);
    $observed = [];

    $response = app(RedactReelHeaders::class)->handle(
        $request,
        function (Request $request) use ($correlate, &$observed) {
            return $correlate->handle($request, function (Request $request) use (&$observed) {
                $observed = [
                    'binding' => $request->attributes->get(Correlation::BINDING_ATTRIBUTE),
                    'rejection' => $request->attributes->get(Correlation::REJECTION_ATTRIBUTE),
                ];

                return response('', 200);
            });
        },
    );
    $correlate->terminate($request, $response);

    expect($observed)->toBe([
        'binding' => null,
        'rejection' => 'not_issued_or_expired',
    ]);
});

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
    expect($headers)->not->toHaveKey('x-reel-session');
    $encodedHeaders = json_encode($headers, JSON_THROW_ON_ERROR);
    expect($encodedHeaders)->not->toContain($sessionId);
    expect($encodedHeaders)->not->toContain('grant');
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

it('serializes the exact Reel contract through stock Nightwatch without raw credentials', function (
    string $mode,
    ?array $expected,
): void {
    $adapter = reelRunJavaScriptCore('jsc-lifecycle-scenario.js');
    $sessionId = $adapter['requestHeaders']['fetch'];
    $grant = $adapter['uploadGrant'];
    config()->set('reel.correlation.context_export', $mode);

    $nightwatch = $this->withSession(['reel.issued_sessions' => [$sessionId => reelIssuedEntry()]])
        ->getJson('/reel-test/correlation/nightwatch', [Correlation::REQUEST_HEADER => $sessionId])
        ->assertOk()
        ->json();
    $headers = json_decode((string) $nightwatch['headers'], true, flags: JSON_THROW_ON_ERROR);
    $context = json_decode((string) $nightwatch['context'], true, flags: JSON_THROW_ON_ERROR);

    expect($headers)->not->toHaveKey('x-reel-session')
        ->and(json_encode($nightwatch, JSON_THROW_ON_ERROR))->not->toContain($grant)
        ->and($context)->toBe($expected === null ? [] : ['reel' => $expected]);
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
        ->and($context['candidates_url'])->toContain(
            'https://reel.example/applications/application-id/sessions?',
            'startedFrom=',
            'startedTo=',
            'path=%2Freel-test%2Fcorrelation',
        );
    expect($context['candidates_url'])->not->toContain($first);
    expect($context['candidates_url'])->not->toContain($second);
});

it('keeps stock Nightwatch dev-only and unmodified', function (): void {
    $root = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $package = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $encoded = json_encode([$root, $package], JSON_THROW_ON_ERROR);

    expect(array_keys($root['require']))->not->toContain('laravel/nightwatch');
    expect(array_keys($root['require']))->not->toContain('laravel/hone');
    expect(array_keys($root['require-dev']))->not->toContain('laravel/nightwatch');
    expect(array_keys($root['require-dev']))->not->toContain('laravel/hone');
    expect(array_keys($package['require']))->not->toContain('laravel/nightwatch');
    expect(array_keys($package['require']))->not->toContain('laravel/hone');
    expect($package['require-dev']['laravel/nightwatch'])->toBe('^1.28')
        ->and($root['repositories'])->toBe([[
            'type' => 'path',
            'url' => 'packages/reel-client',
            'options' => ['symlink' => true],
        ]]);
    expect($encoded)->not->toContain('composer-patches');
    expect($encoded)->not->toContain('cweagans/composer-patches');
    expect($encoded)->not->toContain('nightwatch-fork');
});
