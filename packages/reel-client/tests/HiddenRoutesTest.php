<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\CapturePolicy;
use ArtisanBuild\ReelClient\Http\Middleware\PreventReelCapture;
use ArtisanBuild\ReelClient\KeyMaterial;
use ArtisanBuild\ReelClient\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

uses(TestCase::class);

function reelRecorderMarkup(): string
{
    return Blade::render('<x-reel::recorder />');
}

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::get('/reel-test/allowed', fn (): string => reelRecorderMarkup());

        Route::get('/reel-test/individual', fn (): string => reelRecorderMarkup())
            ->hiddenFromReel();

        Route::hiddenFromReel()->group(function (): void {
            Route::get('/reel-test/group', fn (): string => reelRecorderMarkup());

            Route::prefix('/reel-test/nested')->group(function (): void {
                Route::get('/hidden', fn (): string => reelRecorderMarkup());
            });

            Route::get('/reel-test/cannot-reenable', fn (): string => reelRecorderMarkup())
                ->metadata(['reel' => ['hidden' => false]])
                ->withoutMiddleware(PreventReelCapture::class);
        });

        Route::prefix('/reel-test/registrar')->hiddenFromReel()->group(function (): void {
            Route::get('/hidden', fn (): string => reelRecorderMarkup());
        });

        Route::get('/reel-test/alias', fn (): string => reelRecorderMarkup())
            ->middleware('reel.hidden');
    });
});

it('boots only on an ordinary route', function (): void {
    $this->get('/reel-test/allowed', ['Accept' => 'text/html'])
        ->assertOk()
        ->assertSee('reel-recorder-0.1.0.js', false)
        ->assertDontSee('Reel.start(', false);
});

it('keeps individual group nested registrar and alias routes absolutely hidden', function (string $uri): void {
    config()->set('reel.enabled', true);

    $this->get($uri, ['Accept' => 'text/html'])
        ->assertOk()
        ->assertHeader(CapturePolicy::RESPONSE_HEADER, 'hidden')
        ->assertDontSee('rrweb-2.1.1.js', false)
        ->assertDontSee('reel-recorder-0.1.0.js', false);

    expect(session('reel.current_page_hidden'))->toBeTrue();
})->with([
    '/reel-test/individual',
    '/reel-test/group',
    '/reel-test/nested/hidden',
    '/reel-test/registrar/hidden',
    '/reel-test/alias',
    '/reel-test/cannot-reenable',
]);

it('does not issue a grant after a hidden document and allows no nested override', function (): void {
    $key = KeyMaterial::generate();
    config()->set([
        'reel.url' => 'https://reel.example',
        'reel.application_id' => 'app-id',
        'reel.private_key' => KeyMaterial::encodePrivateKey($key['private']),
    ]);

    $this->get('/reel-test/cannot-reenable', ['Accept' => 'text/html'])->assertOk();
    $this->postJson(route('reel.session-grants.store'), ['consent' => true])->assertForbidden();

    $this->get('/reel-test/allowed', ['Accept' => 'text/html'])->assertOk();
    $this->postJson(route('reel.session-grants.store'), ['consent' => true])->assertOk();
});
