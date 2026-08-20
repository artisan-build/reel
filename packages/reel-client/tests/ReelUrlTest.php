<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Facades\Reel;
use ArtisanBuild\ReelClient\QueryRedactor;
use ArtisanBuild\ReelClient\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

uses(TestCase::class);

it('builds an application scoped filter from only the model primary key', function (): void {
    config()->set([
        'reel.url' => 'https://reel.example',
        'reel.application_id' => 'application/public id',
    ]);
    $user = new class extends Model
    {
        public $incrementing = false;

        protected $keyType = 'string';

        protected $attributes = [
            'id' => 'customer/42',
            'name' => 'Sensitive Name',
            'email' => 'sensitive@example.com',
        ];
    };

    $url = Reel::sessionsUrlFor($user);

    expect($url)->toBe('https://reel.example/applications/application%2Fpublic%20id/sessions?user_id=customer%2F42')
        ->not->toContain('Sensitive', 'sensitive@example.com', 'grant', 'token')
        ->and(Reel::sessionsUrlForId(99))->toEndWith('?user_id=99');
});

it('adds private-surface headers and redacts query strings', function (): void {
    Route::get('/reel-test/private-surface', fn (): string => 'sessions')
        ->middleware('reel.private');

    $this->get('/reel-test/private-surface?user_id=sensitive-id')
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(QueryRedactor::redact('https://reel.example/sessions?user_id=sensitive-id'))
        ->toBe('https://reel.example/sessions?[REDACTED]')
        ->not->toContain('sensitive-id');
});

it('rejects empty control-character and oversized identifiers', function (string $id): void {
    config()->set(['reel.url' => 'https://reel.example', 'reel.application_id' => 'app']);

    expect(fn () => Reel::sessionsUrlForId($id))->toThrow(InvalidArgumentException::class);
})->with(['', "line\nbreak", str_repeat('x', 129)]);
