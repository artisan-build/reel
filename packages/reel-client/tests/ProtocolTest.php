<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Envelope;
use ArtisanBuild\ReelClient\Tests\TestCase;

uses(TestCase::class);

it('defines and serializes the versioned envelope contract', function (): void {
    $envelope = new Envelope(
        applicationId: 'application-1',
        sessionId: 'session-1',
        epochId: 'epoch-1',
        sequence: 0,
        checksum: str_repeat('a', 64),
        eventStartedAt: 100,
        eventEndedAt: 200,
        payload: 'compressed-payload',
        grant: 'signed-grant',
    );

    expect($envelope->toArray())->toBe([
        'envelope_version' => 1,
        'recorder_version' => '0.1.0',
        'rrweb_version' => '2.1.1',
        'compression' => 'gzip',
        'application_id' => 'application-1',
        'session_id' => 'session-1',
        'epoch_id' => 'epoch-1',
        'sequence' => 0,
        'checksum' => str_repeat('a', 64),
        'event_started_at' => 100,
        'event_ended_at' => 200,
        'payload' => 'compressed-payload',
        'grant' => 'signed-grant',
    ])->and(config('reel.recorder.envelope_version'))->toBe(Envelope::VERSION);
});

it('locks exact rrweb artifacts and verifies their bytes', function (): void {
    $root = dirname(__DIR__);
    $lock = json_decode(file_get_contents($root.'/resources/vendor/rrweb.lock.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($lock['package'])->toBe('rrweb')
        ->and($lock['version'])->toBe('2.1.1')
        ->and($lock['source'])->toBe('https://registry.npmjs.org/rrweb/-/rrweb-2.1.1.tgz');

    foreach ($lock['files'] as $file => $checksum) {
        expect(hash_file('sha256', $root.'/resources/vendor/'.$file))->toBe($checksum);
    }
});

it('contains no node package or build manifest in repository sources', function (): void {
    $root = dirname(__DIR__, 3);
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (str_contains((string) $path, '/vendor/') || str_contains((string) $path, '/storage/') || str_contains((string) $path, '/bootstrap/cache/')) {
            continue;
        }
        if (in_array($file->getFilename(), ['package.json', 'package-lock.json', 'npm-shrinkwrap.json'], true)) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
});
