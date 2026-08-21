<?php

declare(strict_types=1);

use App\Jobs\CloudSmokeRoundTrip;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

function cloudManifest(): array
{
    return json_decode(
        file_get_contents(base_path('built-for-cloud.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/** @return array<string, mixed> */
function resolvedCloudConfigWithoutEnvironment(): array
{
    $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->loadEnvironmentFrom('.env.cloud-invariant-absent');
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo json_encode([
    'database.default' => config('database.default'),
    'queue.default' => config('queue.default'),
    'filesystems.default' => config('filesystems.default'),
    'cache.default' => config('cache.default'),
    'session.driver' => config('session.driver'),
], JSON_THROW_ON_ERROR);
PHP;
    $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_CONFIG_CACHE' => base_path('bootstrap/cache/cloud-invariant-config.php'),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => false,
        'QUEUE_CONNECTION' => false,
        'FILESYSTEM_DISK' => false,
        'CACHE_STORE' => false,
        'SESSION_DRIVER' => false,
    ]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps the catalog manifest aligned with live application configuration and scheduler registration', function (): void {
    $manifest = cloudManifest();
    $resourceList = $manifest['resources'];
    $resources = collect($resourceList)->keyBy('id');
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
        ->and($resourceList)->toHaveCount(5)
        ->and(array_column($resourceList, 'id'))->toBe([
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
    $managedConfig = [
        'database.default',
        'queue.default',
        'filesystems.default',
        'cache.default',
        'session.driver',
    ];
    $resolved = resolvedCloudConfigWithoutEnvironment();

    expect($resolved)->toHaveKeys($managedConfig);

    foreach ($managedConfig as $key) {
        expect($resolved[$key])->toBeNull("Cloud-managed config key {$key} resolves to an application-set value");
    }

    $managedVariable = '(?:DB_[A-Z0-9_]+|QUEUE_CONNECTION|CACHE_STORE|SESSION_DRIVER|FILESYSTEM_DISK|AWS_[A-Z0-9_]+|SQS_[A-Z0-9_]+|REDIS_[A-Z0-9_]+)';

    foreach (glob(base_path('config/*.php')) ?: [] as $path) {
        $contents = file_get_contents($path);
        preg_match_all("/env\\(\\s*['\"](?<variable>{$managedVariable})['\"]\\s*,/", $contents, $matches);

        foreach ($matches['variable'] as $variable) {
            expect($variable)->toBeNull("Cloud-managed variable {$variable} has a fallback in {$path}");
        }
    }

    $runtimeKey = '(?:database|queue|cache|filesystems)\.[A-Za-z0-9_.-]+|session\.driver';

    $runtimePaths = [app_path(), base_path('packages/reel-client/src'), base_path('bootstrap'), base_path('routes')];

    foreach ((new Finder)->files()->in($runtimePaths)->name('*.php') as $file) {
        $contents = $file->getContents();
        $patterns = [
            "/config\\(\\s*\\[\\s*['\"](?<key>{$runtimeKey})['\"]\\s*=>/",
            "/config\\(\\s*\\)\\s*->set\\(\\s*['\"](?<key>{$runtimeKey})['\"]/",
            "/Config::set\\(\\s*['\"](?<key>{$runtimeKey})['\"]/",
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $contents, $matches);

            foreach ($matches['key'] as $key) {
                expect($key)->toBeNull("Runtime config key {$key} is shadowed in {$file->getRealPath()}");
            }
        }
    }
});

it('scans every deployment-carrying file for Cloud managed assignments', function (): void {
    $tracked = new Process(['git', 'ls-files', '-z'], base_path());
    $tracked->mustRun();
    $trackedFiles = collect([...explode("\0", $tracked->getOutput()), 'built-for-cloud.json'])
        ->filter()
        ->unique()
        ->mapWithKeys(fn (string $path): array => [$path => file_get_contents(base_path($path))]);
    $managedVariable = '(?:DB_[A-Z0-9_]+|QUEUE_CONNECTION|CACHE_STORE|SESSION_DRIVER|FILESYSTEM_DISK|AWS_[A-Z0-9_]+|SQS_[A-Z0-9_]+|REDIS_[A-Z0-9_]+)';
    $deployedConfiguration = $trackedFiles->filter(function (string $contents, string $path): bool {
        $basename = basename($path);

        return $path === 'built-for-cloud.json'
            || $path === 'composer.json'
            || str_starts_with($path, '.cloud/')
            || str_starts_with($path, '.github/workflows/')
            || str_starts_with($path, 'scripts/')
            || str_starts_with($path, 'bin/')
            || $basename === 'Dockerfile'
            || str_starts_with($basename, 'Dockerfile.')
            || $basename === 'Procfile'
            || str_ends_with($path, '.sh')
            || (str_starts_with($contents, '#!') && preg_match('/^#!.*(?:ba|z|da)?sh\\b/', $contents) === 1);
    });
    $patterns = [
        "/(?mi)(?:^|[\\s\"'])\\b(?:ENV|ARG)\\s+(?<variable>{$managedVariable})(?:\\s*=\\s*|\\s+)\\S+/",
        "/(?m)(?:^|[\\s;\"'])\\b(?:export\\s+)?(?<variable>{$managedVariable})\\s*=\\s*\\S+/",
        "/(?m)^\\s*['\"]?(?<variable>{$managedVariable})['\"]?\\s*:\\s*\\S+/",
    ];

    expect($deployedConfiguration)->not->toBeEmpty();

    foreach ($deployedConfiguration as $path => $contents) {
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $contents, $matches);

            foreach ($matches['variable'] as $variable) {
                expect($variable)->toBeNull("Cloud-managed variable {$variable} is assigned in {$path}");
            }
        }
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

it('documents the supported local and non-interactive first-admin bootstrap forms', function (): void {
    $options = Artisan::all()['create-admin']->getDefinition()->getOptions();
    $deployment = file_get_contents(base_path('docs/deployment.md'));

    expect($options)->toHaveKeys([
        'execute',
        'email',
        'name',
        'password-hash',
        'environment',
    ])->and($deployment)
        ->toContain('php artisan create-admin --environment=<environment>')
        ->toContain('php artisan create-admin --execute --email=<email> --name=<name> --password-hash=<bcrypt-hash> --no-interaction')
        ->toContain('non-interactive');
});

it('runs the configured queue and removes its object storage probe', function (): void {
    config()->set('filesystems.default', 'smoke');
    config()->set('queue.default', 'database');
    Storage::fake('smoke', ['driver' => 's3']);

    $this->artisan('reel:smoke')
        ->assertSuccessful()
        ->expectsOutputToContain('Reel is ready');

    expect(Storage::disk('smoke')->allFiles('reel/smoke'))->toBe([]);
});

it('fails readiness when the database has a pending migration', function (): void {
    config()->set('filesystems.default', 'smoke-pending-migration');
    config()->set('queue.default', 'database');
    Storage::fake('smoke-pending-migration', ['driver' => 's3']);
    DB::table('migrations')->where('migration', '2026_08_21_000001_harden_retention_concurrency')->delete();

    $this->artisan('reel:smoke')
        ->assertFailed()
        ->expectsOutputToContain('The database has pending migrations.');
});

it('fails readiness when S3 object storage is unwritable', function (): void {
    config()->set('filesystems.default', 'unwritable');
    config()->set('queue.default', 'database');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('getConfig')->once()->andReturn(['driver' => 's3']);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    $disk->shouldReceive('exists')->once()->andReturnFalse();
    Storage::set('unwritable', $disk);

    $this->artisan('reel:smoke')
        ->assertFailed()
        ->expectsOutputToContain('Object storage is not writable and readable.');
});

it('removes the storage probe when a later readiness check fails', function (): void {
    config()->set('filesystems.default', 'smoke-later-failure');
    config()->set('queue.default', 'database');
    $objects = [];
    $probeWritten = false;
    $cleanupStarted = false;
    $manifestPath = base_path('built-for-cloud.json');
    $originalManifest = file_get_contents($manifestPath);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('getConfig')->once()->andReturn(['driver' => 's3']);
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$objects, &$probeWritten): bool {
        $objects[$path] = $contents;
        $probeWritten = str_starts_with($contents, 'storage-') || $probeWritten;

        return true;
    });
    $disk->shouldReceive('get')->andReturnUsing(fn (string $path): ?string => $objects[$path] ?? null);
    $disk->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => isset($objects[$path]));
    $disk->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use (&$objects, &$cleanupStarted): bool {
        $cleanupStarted = true;
        unset($objects[$path]);

        return true;
    });
    Storage::set('smoke-later-failure', $disk);
    $driftedManifest = json_decode($originalManifest, true, flags: JSON_THROW_ON_ERROR);
    $driftedManifest['scheduler'][] = ['command' => 'reel:smoke', 'expression' => '0 0 * * *'];
    file_put_contents($manifestPath, json_encode($driftedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    try {
        $exitCode = Artisan::call('reel:smoke');
    } finally {
        file_put_contents($manifestPath, $originalManifest);
    }

    expect($exitCode)->toBe(1)
        ->and($probeWritten)->toBeTrue()
        ->and($cleanupStarted)->toBeTrue()
        ->and($objects)->toBe([]);
});

it('fails readiness when S3 reports that scratch deletion failed', function (): void {
    config()->set('filesystems.default', 'smoke-delete-failure');
    config()->set('queue.default', 'database');
    $objects = [];
    $deleteAttempted = false;
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('getConfig')->once()->andReturn(['driver' => 's3']);
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$objects): bool {
        $objects[$path] = $contents;

        return true;
    });
    $disk->shouldReceive('get')->andReturnUsing(fn (string $path): ?string => $objects[$path] ?? null);
    $disk->shouldReceive('exists')->andReturnUsing(function (string $path) use (&$deleteAttempted, &$objects): bool {
        return $deleteAttempted ? false : isset($objects[$path]);
    });
    $disk->shouldReceive('delete')->once()->andReturnUsing(function () use (&$deleteAttempted): bool {
        $deleteAttempted = true;

        return false;
    });
    Storage::set('smoke-delete-failure', $disk);

    $this->artisan('reel:smoke')
        ->assertFailed()
        ->expectsOutputToContain('Object storage cleanup failed.');

    expect($deleteAttempted)->toBeTrue()
        ->and($objects)->not->toBeEmpty();
});

it('rejects a local filesystem before reporting Cloud readiness', function (): void {
    config()->set('filesystems.default', 'smoke-local');
    config()->set('queue.default', 'database');
    Storage::fake('smoke-local', ['driver' => 'local']);

    $this->artisan('reel:smoke')
        ->assertFailed()
        ->expectsOutputToContain('The configured filesystem driver [local] is not S3-backed.');
});

it('rejects an inline queue before reporting Cloud readiness', function (): void {
    config()->set('filesystems.default', 'smoke-inline-queue');
    config()->set('queue.default', 'sync');
    Storage::fake('smoke-inline-queue', ['driver' => 's3']);

    $this->artisan('reel:smoke')
        ->assertFailed()
        ->expectsOutputToContain('The configured queue driver [sync] is inline.');
});

it('works only the dedicated smoke queue and leaves customer jobs untouched', function (): void {
    config()->set('filesystems.default', 'smoke-dedicated-queue');
    config()->set('queue.default', 'database');
    Storage::fake('smoke-dedicated-queue', ['driver' => 's3']);
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => 'customer-job-sentinel',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);

    $this->artisan('reel:smoke')->assertSuccessful();

    expect(DB::table('jobs')->where('queue', 'default')->value('payload'))->toBe('customer-job-sentinel')
        ->and(DB::table('jobs')->where('queue', 'reel-smoke')->exists())->toBeFalse();
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
