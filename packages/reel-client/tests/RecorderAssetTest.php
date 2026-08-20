<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Tests\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

uses(TestCase::class);

function reelRecorderSource(): string
{
    return file_get_contents(dirname(__DIR__).'/resources/js/reel-recorder.js');
}

function reelJavaScriptCorePath(): ?string
{
    $configured = getenv('JSC_BINARY');
    $frameworkBinary = '/System/Library/Frameworks/JavaScriptCore.framework/Versions/Current/Helpers/jsc';

    foreach ([$configured, $frameworkBinary, (new ExecutableFinder)->find('jsc')] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/** @return array<string, mixed> */
function reelRunJavaScriptCore(string $scenario): array
{
    $binary = reelJavaScriptCorePath();

    if ($binary === null) {
        test()->markTestSkipped('JavaScriptCore is unavailable; real recorder execution is skipped on this platform.');
    }

    $root = dirname(__DIR__);
    $process = new Process([
        $binary,
        $root.'/tests/Fixtures/jsc-runtime.js',
        $root.'/resources/js/reel-recorder.js',
        $root.'/tests/Fixtures/'.$scenario,
    ]);
    $process->run();

    expect($process->isSuccessful())
        ->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

it('serves immutable precompiled rrweb and recorder assets', function (): void {
    $this->get(route('reel.assets.rrweb'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

    $this->get(route('reel.assets.recorder'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
        ->assertSee('CompressionStream', false);
});

it('executes the shipped sanitizer against hostile snapshots and mutations', function (): void {
    $result = reelRunJavaScriptCore('jsc-sanitizer-scenario.js');
    $nodes = $result['snapshot']['data']['node']['childNodes'];

    expect($nodes[0]['attributes'])->toBe(['type' => 'hidden', 'value' => '***'])
        ->and($nodes[1]['attributes']['value'])->toBe('***')
        ->and($nodes[2]['attributes']['value'])->toBe('***')
        ->and($nodes[3]['childNodes'][0]['textContent'])->toBe('***')
        ->and($nodes[4]['attributes'])->toBe([])
        ->and($nodes[5]['attributes'])->not->toHaveKey('href')
        ->and($nodes[5]['attributes']['style'])->toBe('width: 20px; background: none; mask: none')
        ->and($nodes[6]['tagName'])->toBe('div')
        ->and($nodes[6]['attributes'])->toMatchArray([
            'width' => '20',
            'height' => '10',
            'data-reel-blocked' => 'img',
        ])
        ->and(array_column(array_slice($nodes, 7), 'tagName'))->toBe(['div', 'div', 'div'])
        ->and(array_column(array_slice($nodes, 7), 'attributes'))->each->toHaveKey('data-reel-blocked')
        ->and($result['meta']['data']['href'])->toBe('/account')
        ->and($result['navigation']['data']['href'])->toBe('/next')
        ->and($result['attributeMutation']['data']['attributes'][0]['attributes'])->toBe([
            'style' => 'display: block; background: none',
        ])
        ->and(json_encode($result['snapshot'], JSON_THROW_ON_ERROR))->not->toContain(
            'input-secret',
            'textarea-secret',
            'select-secret',
            'editable-secret',
            'inertia-secret',
            'livewire-secret',
            'legacy-secret',
            'data:image',
            'https://',
        );
});

it('executes CSSOM sanitization for every rrweb stylesheet source', function (): void {
    $result = reelRunJavaScriptCore('jsc-sanitizer-scenario.js');
    $encoded = json_encode($result['cssRules'], JSON_THROW_ON_ERROR);

    expect($result['cssRules'])->toHaveCount(3)
        ->and($encoded)->toContain('background:none', 'linear-gradient(red, blue), none', '@font-face{src:none}')
        ->not->toMatch('/url\s*\(/i')
        ->not->toContain('https://')
        ->and($result['unsafeCss'])->toBeNull();
});

it('starts once during concurrent calls and requires explicit consent', function (): void {
    $result = reelRunJavaScriptCore('jsc-lifecycle-scenario.js');

    expect($result['initial']['noConsent']['state'])->toBe('awaiting_consent')
        ->and($result['initial']['sameStartPromise'])->toBeTrue()
        ->and($result['initial']['grantRequests'])->toBe(1)
        ->and($result['initial']['recordCalls'])->toBe(1)
        ->and($result['initial']['intervalCalls'])->toBe(1);
});

it('latches hidden responses until an allowed navigation starts a fresh recording', function (): void {
    $result = reelRunJavaScriptCore('jsc-lifecycle-scenario.js');

    expect($result['hidden']['status']['state'])->toBe('hidden')
        ->and($result['hidden']['grantRequests'])->toBe(1)
        ->and($result['hidden']['recordCalls'])->toBe(1)
        ->and($result['hidden']['storedSession'])->toBeNull()
        ->and($result['restarted']['state'])->toBe('recording')
        ->and($result['grantRequests'])->toBe(2)
        ->and($result['recordCalls'])->toBe(2);
});

it('stops recording at the signed maximum event time', function (): void {
    $result = reelRunJavaScriptCore('jsc-lifecycle-scenario.js');

    expect($result['expired']['state'])->toBe('stopped')
        ->and($result['expired']['reason'])->toBe('max_event_time')
        ->and($result['expired']['incomplete'])->toBeFalse();
});

it('produces reconstructable FullSnapshot-first epochs with monotonic sequences', function (): void {
    $result = reelRunJavaScriptCore('jsc-lifecycle-scenario.js');
    $uploads = $result['uploads'];
    $firstEvents = json_decode(base64_decode($uploads[0]['payload'], true), true, flags: JSON_THROW_ON_ERROR);
    $secondEvents = json_decode(base64_decode($uploads[1]['payload'], true), true, flags: JSON_THROW_ON_ERROR);
    $newEpochEvents = json_decode(base64_decode($uploads[2]['payload'], true), true, flags: JSON_THROW_ON_ERROR);

    expect($result['initial']['bufferedTypes'])->toBe([2, 4, 3])
        ->and($uploads)->toHaveCount(3)
        ->and($firstEvents[0]['type'])->toBe(2)
        ->and($newEpochEvents[0]['type'])->toBe(2)
        ->and($secondEvents[0]['data']['text'])->toBe('***')
        ->and(array_column(array_slice($uploads, 0, 2), 'sequence'))->toBe([0, 1])
        ->and($uploads[0]['epoch_id'])->toBe($uploads[1]['epoch_id'])
        ->and($uploads[2]['epoch_id'])->not->toBe($uploads[0]['epoch_id'])
        ->and($uploads[2]['sequence'])->toBe(0);
});

it('preserves host fetch and xhr return semantics and bounds failure', function (): void {
    $source = reelRecorderSource();

    expect($source)->toContain('const result = Reflect.apply(state.originalFetch, receiver, args)')
        ->and($source)->toContain('return result')
        ->and($source)->not->toContain('response.text(', 'response.json().then')
        ->and($source)->toContain('return Reflect.apply(state.originalXhrOpen, this, arguments)')
        ->and($source)->toContain('return Reflect.apply(state.originalXhrSend, this, arguments)')
        ->and($source)->toContain("markIncomplete('buffer_ceiling')")
        ->and($source)->toContain("markIncomplete('retry_ceiling')")
        ->and($source)->toContain("markIncomplete('circuit_open')")
        ->and($source)->toContain('Math.random() * delay / 2')
        ->and($source)->not->toContain('console.log', 'console.debug');
});
