<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\ReelClientServiceProvider;

it('registers the Reel client package', function (): void {
    $this->assertInstanceOf(
        ReelClientServiceProvider::class,
        app()->getProvider(ReelClientServiceProvider::class),
    );
    $this->assertNotNull(config('reel'));
});

it('installs the shared Reel protocol package at runtime for ingest verification', function (): void {
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require'])->toHaveKey('artisan-build/reel-client', '*')
        ->and($composer['require-dev'])->not->toHaveKey('artisan-build/reel-client')
        ->and($composer['repositories'])->toContain([
            'type' => 'path',
            'url' => 'packages/reel-client',
            'options' => ['symlink' => true],
        ]);
});
