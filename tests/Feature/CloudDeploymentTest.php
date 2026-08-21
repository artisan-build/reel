<?php

declare(strict_types=1);

use App\Jobs\CloudSmokeRoundTrip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function cloudManifest(): array
{
    return json_decode(
        file_get_contents(base_path('built-for-cloud.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

it('keeps the catalog manifest aligned with live application configuration and scheduler registration', function (): void {
    $manifest = cloudManifest();
    $resources = collect($manifest['resources'])->keyBy('id');
    $liveDrivers = [
        'database' => config('database.connections.pgsql.driver'),
        'object_storage' => config('filesystems.disks.s3.driver'),
        'queue' => config('queue.connections.sqs.driver'),
    ];
    $schedule = new Process([PHP_BINARY, 'artisan', 'schedule:list', '--json'], base_path());
    $schedule->mustRun();
    $registeredScheduler = collect(json_decode($schedule->getOutput(), true, flags: JSON_THROW_ON_ERROR))
        ->filter(fn (array $event): bool => str_starts_with((string) $event['command'], 'php artisan reel:'))
        ->map(fn (array $event): array => [
            'command' => Str::after($event['command'], 'php artisan '),
            'expression' => $event['expression'],
        ])
        ->values()
        ->all();

    expect($manifest)->toMatchArray([
        'name' => 'Reel',
        'slug' => 'reel',
        'state' => 'experimental',
        'limitations' => 'docs/limitations.md',
    ])->and($manifest['description'])->toBeString()->not->toBeEmpty()
        ->and($resources->keys()->all())->toBe([
            'application',
            'database',
            'object_storage',
            'queue',
            'scheduler',
        ])
        ->and($resources->pluck('type', 'id')->all())->toBe([
            'application' => 'application',
            'database' => 'postgresql',
            'object_storage' => 'laravel-object-storage',
            'queue' => 'managed-queue',
            'scheduler' => 'scheduler',
        ])
        ->and($resources->contains(fn (array $resource): bool => str_contains((string) $resource['type'], 'cache')))->toBeFalse()
        ->and($resources->get('object_storage')['visibility'])->toBe('private')
        ->and($resources->only(array_keys($liveDrivers))->map(fn (array $resource): string => $resource['driver'])->all())
        ->toBe($liveDrivers)
        ->and($manifest['scheduler'])->toBe($registeredScheduler);
});

it('never deploys configuration that shadows Cloud managed resource values', function (): void {
    $tracked = new Process(['git', 'ls-files', '-z'], base_path());
    $tracked->mustRun();
    $deployedConfiguration = collect([...explode("\0", $tracked->getOutput()), 'built-for-cloud.json'])
        ->filter()
        ->unique()
        ->filter(fn (string $path): bool => $path === 'built-for-cloud.json'
            || $path === 'composer.json'
            || str_starts_with($path, '.cloud/')
            || str_starts_with($path, '.github/workflows/')
            || preg_match('/(^|\/)(Dockerfile|Procfile|\.env\.(production|cloud))$/', $path) === 1)
        ->mapWithKeys(fn (string $path): array => [$path => file_get_contents(base_path($path))]);
    $assignment = '/(?m)^\s*(?:(?:export\s+)?(?:DB_CONNECTION|QUEUE_CONNECTION|CACHE_STORE|FILESYSTEM_DISK|AWS_[A-Z0-9_]+|SQS_[A-Z0-9_]+)\s*(?:=|:)|[\'\"](?:DB_CONNECTION|QUEUE_CONNECTION|CACHE_STORE|FILESYSTEM_DISK|AWS_[A-Z0-9_]+|SQS_[A-Z0-9_]+)[\'\"]\s*:)\s*\S+/';

    expect($deployedConfiguration)->not->toBeEmpty();

    foreach ($deployedConfiguration as $path => $contents) {
        expect($contents)->not->toMatch($assignment, "Cloud-managed value is assigned in {$path}");
    }

    $productionDefaults = [
        [base_path('config/database.php'), 'DB_CONNECTION', 'pgsql'],
        [base_path('config/queue.php'), 'QUEUE_CONNECTION', 'sqs'],
        [base_path('config/filesystems.php'), 'FILESYSTEM_DISK', 's3'],
    ];

    foreach ($productionDefaults as [$path, $variable, $value]) {
        expect(file_get_contents($path))->not->toMatch(
            "/env\\(\\s*['\"]{$variable}['\"]\\s*,\\s*['\"]{$value}['\"]\\s*\\)/",
        );
    }

    foreach (glob(base_path('config/*.php')) ?: [] as $path) {
        expect(file_get_contents($path))->not->toMatch(
            '/env\(\s*[\'\"](?:AWS_|SQS_)[A-Z0-9_]+[\'\"]\s*,/',
            "Cloud-managed AWS/SQS value has a fallback in {$path}",
        );
    }
});

it('uses deployment commands that cannot materialize the local environment template', function (): void {
    $manifest = cloudManifest();
    $commands = [...$manifest['build_commands'], ...$manifest['post_deploy_commands']];

    expect($manifest['build_commands'])->not->toBeEmpty()
        ->and($manifest['post_deploy_commands'])->not->toBeEmpty();

    foreach ($commands as $command) {
        expect($command)->not->toContain('composer setup')
            ->not->toContain('.env.example');
    }
});

it('ships both Cloud storage and queue drivers as runtime dependencies', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require'])->toHaveKeys([
        'aws/aws-sdk-php',
        'league/flysystem-aws-s3-v3',
    ])->and($composer['require-dev'])->not->toHaveKeys([
        'aws/aws-sdk-php',
        'league/flysystem-aws-s3-v3',
    ]);
});

it('runs the configured queue and removes its object storage probe', function (): void {
    config()->set('filesystems.default', 'smoke');
    config()->set('queue.default', 'database');
    Storage::fake('smoke');

    $this->artisan('reel:smoke')
        ->assertSuccessful()
        ->expectsOutputToContain('Reel is ready');

    expect(Storage::disk('smoke')->allFiles('reel/smoke'))->toBe([]);
});

it('fails readiness when the database has a pending migration', function (): void {
    config()->set('filesystems.default', 'smoke-pending-migration');
    Storage::fake('smoke-pending-migration');
    DB::table('migrations')->where('migration', '2026_08_21_000001_harden_retention_concurrency')->delete();

    $this->artisan('reel:smoke')
        ->assertFailed()
        ->expectsOutputToContain('The database has pending migrations.');
});

it('fails on an unwritable disk and still leaves no scratch object', function (): void {
    $root = storage_path('framework/testing/reel-smoke-unwritable');
    file_put_contents($root, 'not a directory');
    config()->set('filesystems.default', 'unwritable');
    config()->set('filesystems.disks.unwritable', [
        'driver' => 'local',
        'root' => $root,
        'throw' => false,
    ]);

    try {
        $this->artisan('reel:smoke')
            ->assertFailed()
            ->expectsOutputToContain('Unable to create a directory');

        expect(glob($root.'/reel/smoke/*') ?: [])->toBe([]);
    } finally {
        unlink($root);
    }
});

it('removes the storage probe when a later readiness check fails', function (): void {
    config()->set('filesystems.default', 'smoke-later-failure');
    config()->set('queue.default', 'missing-smoke-connection');
    Storage::fake('smoke-later-failure');

    $this->artisan('reel:smoke')
        ->assertFailed()
        ->expectsOutputToContain('The [missing-smoke-connection] queue connection has not been configured.');

    expect(Storage::disk('smoke-later-failure')->allFiles('reel/smoke'))->toBe([]);
});

it('does not let a delayed smoke job recreate a cleaned probe', function (): void {
    config()->set('filesystems.default', 'smoke-delayed-job');
    Storage::fake('smoke-delayed-job');
    $path = 'reel/smoke/delayed.txt';
    Storage::disk('smoke-delayed-job')->put($path, 'original-probe');
    Storage::disk('smoke-delayed-job')->delete($path);

    (new CloudSmokeRoundTrip(
        $path,
        'original-probe',
        'late-queue-write',
        now()->addMinute()->getTimestamp(),
    ))->handle();

    expect(Storage::disk('smoke-delayed-job')->exists($path))->toBeFalse();
});
