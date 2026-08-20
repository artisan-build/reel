<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Tests\TestCase;

uses(TestCase::class);

function reelRecorderSource(): string
{
    return file_get_contents(dirname(__DIR__).'/resources/js/reel-recorder.js');
}

/** @return array<string, mixed> */
function reelSanitizerRules(): array
{
    preg_match("/JSON\\.parse\\('([^']+)'\\)/", reelRecorderSource(), $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
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

it('ships immutable sanitizer rules consumed by snapshot and mutation sanitizers', function (): void {
    $rules = reelSanitizerRules();
    $source = reelRecorderSource();

    expect($rules['hydrationAttributes'])->toBe(['data-page', 'wire:snapshot', 'wire:initial-data'])
        ->and($rules['maskedTags'])->toBe(['input', 'select', 'textarea'])
        ->and($rules['contentEditableAttribute'])->toBe('contenteditable')
        ->and($rules['blockedTags'])->toContain('canvas', 'video', 'audio', 'iframe')
        ->and($rules['urlAttributes'])->toContain('href', 'src', 'srcset', 'action', 'poster', 'xlink:href')
        ->and($source)->toContain('event.data.node = sanitizeNode')
        ->and($source)->toContain('addition.node = sanitizeNode')
        ->and($source)->toContain('sanitizeAttributeMutation')
        ->and($source)->toContain('state.maskedNodeIds.has(text.id)');
});

it('applies the shipped declarative rules to hostile fixture attributes', function (): void {
    $rules = reelSanitizerRules();
    $attributes = [
        'value' => 'input-secret',
        'data-page' => '{"token":"inertia-secret"}',
        'wire:snapshot' => 'livewire-secret',
        'wire:initial-data' => 'legacy-secret',
        'href' => 'https://host.example/account?token=url-secret#fragment',
        'src' => 'data:image/png;base64,image-secret',
        'srcset' => 'https://cdn.example/secret.png 2x',
        'style' => 'width: 20px; height: 10px; background: url(https://cdn.example/private.png); color: red',
    ];

    foreach ([...$rules['hydrationAttributes'], ...$rules['urlAttributes']] as $attribute) {
        unset($attributes[$attribute]);
    }
    $attributes['value'] = $rules['maskText'];
    $attributes['style'] = implode('; ', array_filter(
        explode(';', $attributes['style']),
        fn (string $declaration): bool => ! str_contains(strtolower($declaration), 'url('),
    ));

    expect($attributes)->toBe([
        'value' => '***',
        'style' => 'width: 20px;  height: 10px;  color: red',
    ])->and(json_encode($attributes, JSON_THROW_ON_ERROR))
        ->not->toContain('secret', 'http', 'base64', 'url(');
});

it('masks contenteditable text and blocks media through the same rule registry', function (): void {
    $rules = reelSanitizerRules();
    $fixtures = [
        ['tag' => 'input', 'text' => 'hidden-value'],
        ['tag' => 'div', 'attributes' => ['contenteditable' => 'true'], 'text' => 'editable-secret'],
        ['tag' => 'canvas', 'text' => 'pixels'],
        ['tag' => 'video', 'text' => 'media'],
        ['tag' => 'audio', 'text' => 'media'],
    ];

    $result = array_map(function (array $fixture) use ($rules): array {
        $masked = in_array($fixture['tag'], $rules['maskedTags'], true)
            || array_key_exists($rules['contentEditableAttribute'], $fixture['attributes'] ?? []);
        $blocked = in_array($fixture['tag'], $rules['blockedTags'], true);

        return [
            'tag' => $blocked ? 'div' : $fixture['tag'],
            'text' => $blocked ? '' : ($masked ? $rules['maskText'] : $fixture['text']),
        ];
    }, $fixtures);

    expect($result)->toBe([
        ['tag' => 'input', 'text' => '***'],
        ['tag' => 'div', 'text' => '***'],
        ['tag' => 'div', 'text' => ''],
        ['tag' => 'div', 'text' => ''],
        ['tag' => 'div', 'text' => ''],
    ]);
});

it('strips query and fragment metadata before buffering', function (): void {
    $source = reelRecorderSource();

    expect($source)->toContain('event.data.href = stripQueryAndFragment(event.data.href)')
        ->and($source)->toContain('return value.split(/[?#]/, 1)[0]')
        ->and($source)->toContain('path: stripQueryAndFragment(url)');
});

it('requires explicit consent and keeps repeated starts idempotent', function (): void {
    $source = reelRecorderSource();

    expect($source)->toContain('if (state.started) return status()')
        ->and($source)->toContain('if (settings.consent !== true)')
        ->and($source)->toContain('settings.refuseOnGpc === true && navigator.globalPrivacyControl === true')
        ->and($source)->toContain("Symbol.for('artisan-build.reel.recorder')")
        ->and($source)->not->toMatch('/\bstart\(\{\s*consent:\s*true/');
});

it('preserves host fetch and xhr semantics and bounds failure', function (): void {
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
