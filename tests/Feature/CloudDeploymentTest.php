<?php

declare(strict_types=1);

use App\Jobs\CloudSmokeRoundTrip;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ManagedQueueConnector;

function cloudManifest(): array
{
    return json_decode(
        file_get_contents(base_path('built-for-cloud.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * Configure the connection shape Laravel Cloud actually provisions: a managed queue whose
 * single queue is named for the deployment, where an invented queue name does not exist and
 * never will. Readiness tests run against this rather than the database driver, on which the
 * probe's isolated queue name works fine and hides the failure production sees.
 */
function useManagedQueue(string $provisioned = 'reel-queue-927aa415'): void
{
    Queue::extend('managed-queue', fn (): ManagedQueueConnector => new ManagedQueueConnector(resolve('db')));

    config()->set('queue.connections.managed', [
        'driver' => 'managed-queue',
        'connection' => null,
        'table' => 'jobs',
        'queue' => $provisioned,
        'retry_after' => 90,
    ]);
    config()->set('queue.default', 'managed');
}

/** @return array<string, mixed> */
function resolvedCloudConfig(array $environment = []): array
{
    $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->loadEnvironmentFrom('.env.cloud-invariant-absent');
$app->bootstrapWith([
    Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);
echo json_encode([
    'database.default' => config('database.default'),
    'database.redis.client' => config('database.redis.client'),
    'queue.default' => config('queue.default'),
    'filesystems.default' => config('filesystems.default'),
    'cache.default' => config('cache.default'),
    'session.driver' => config('session.driver'),
], JSON_THROW_ON_ERROR);
PHP;
    $process = new Process([PHP_BINARY, '-r', $script], base_path(), array_replace([
        'APP_CONFIG_CACHE' => base_path('bootstrap/cache/cloud-invariant-config.php'),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => false,
        'QUEUE_CONNECTION' => false,
        'FILESYSTEM_DISK' => false,
        'CACHE_STORE' => false,
        'SESSION_DRIVER' => false,
    ], $environment));
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function requestCloudMetadataWithoutRedisClient(): Process
{
    $script = <<<'PHP'
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->loadEnvironmentFrom('.env.cloud-invariant-absent');

$request = Illuminate\Http\Request::create('/bfc/meta', 'GET', server: [
    'HTTP_ACCEPT' => 'application/json',
    'REMOTE_ADDR' => '127.0.0.1',
]);
$app->instance('request', $request);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$client = new class
{
    public function get(string $key): null
    {
        return null;
    }

    public function eval(string $script, int $keys, mixed ...$arguments): bool
    {
        return true;
    }

    public function incrby(string $key, int $value): int
    {
        return $value;
    }

    public function setex(string $key, int $seconds, mixed $value): bool
    {
        return true;
    }
};

$connection = new class($client) extends Illuminate\Redis\Connections\Connection
{
    public function __construct(object $client)
    {
        $this->client = $client;
    }

    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}
};

$connector = new class($connection) implements Illuminate\Contracts\Redis\Connector
{
    public function __construct(private Illuminate\Redis\Connections\Connection $connection) {}

    public function connect(array $config, array $options): Illuminate\Redis\Connections\Connection
    {
        return $this->connection;
    }

    public function connectToCluster(array $config, array $clusterOptions, array $options): Illuminate\Redis\Connections\Connection
    {
        return $this->connection;
    }
};

$app->make('redis')->extend('phpredis', fn (): Illuminate\Contracts\Redis\Connector => $connector);

$response = $kernel->handle($request);

echo $response->getStatusCode();

$kernel->terminate($request, $response);
PHP;

    return new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_CONFIG_CACHE' => base_path('bootstrap/cache/cloud-redis-management-test.php'),
        'APP_DEBUG' => 'false',
        'APP_ENV' => 'testing',
        'CACHE_STORE' => 'redis',
        'REDIS_CLIENT' => false,
    ]);
}

/** @return array<string, mixed> */
function validatedCloudRedisConfig(): array
{
    $script = <<<'PHP'
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->loadEnvironmentFrom('.env.cloud-invariant-absent');
$app->bootstrapWith([
    Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);

$connector = new class extends Illuminate\Redis\Connectors\PhpRedisConnector
{
    public function validatedBackoffAlgorithm(mixed $algorithm): int
    {
        return $this->parseBackoffAlgorithm($algorithm);
    }
};

$default = config('database.redis.default');
$cache = config('database.redis.cache');

echo json_encode([
    'client' => config('database.redis.client'),
    'options' => config('database.redis.options'),
    'default' => Illuminate\Support\Arr::only($default, [
        'host',
        'port',
        'database',
        'max_retries',
        'backoff_algorithm',
        'backoff_base',
        'backoff_cap',
    ]),
    'cache' => Illuminate\Support\Arr::only($cache, [
        'host',
        'port',
        'database',
        'max_retries',
        'backoff_algorithm',
        'backoff_base',
        'backoff_cap',
    ]),
    'password_present' => $default['password'] === 'cloud-password-placeholder',
    'validated_default_algorithm' => $connector->validatedBackoffAlgorithm($default['backoff_algorithm']),
    'validated_cache_algorithm' => $connector->validatedBackoffAlgorithm($cache['backoff_algorithm']),
], JSON_THROW_ON_ERROR);
PHP;
    $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_CONFIG_CACHE' => base_path('bootstrap/cache/cloud-redis-defaults-test.php'),
        'APP_ENV' => 'testing',
        'APP_NAME' => false,
        'REDIS_CLIENT' => false,
        'REDIS_URL' => false,
        'REDIS_HOST' => 'cloud-redis.internal',
        'REDIS_USERNAME' => false,
        'REDIS_PASSWORD' => 'cloud-password-placeholder',
        'REDIS_PORT' => '6380',
        'REDIS_CLUSTER' => false,
        'REDIS_PREFIX' => false,
        'REDIS_PERSISTENT' => false,
        'REDIS_DB' => false,
        'REDIS_CACHE_DB' => false,
        'REDIS_MAX_RETRIES' => false,
        'REDIS_BACKOFF_ALGORITHM' => false,
        'REDIS_BACKOFF_BASE' => false,
        'REDIS_BACKOFF_CAP' => false,
    ]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('serves the management endpoint when Cloud does not inject a Redis client selector', function (): void {
    $process = requestCloudMetadataWithoutRedisClient();
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('200');
});

it('boots phpredis configuration from the connection values Cloud injects', function (): void {
    $resolved = validatedCloudRedisConfig();
    $connectionDefaults = [
        'host' => 'cloud-redis.internal',
        'port' => '6380',
        'database' => '0',
        'max_retries' => 3,
        'backoff_algorithm' => 'decorrelated_jitter',
        'backoff_base' => 100,
        'backoff_cap' => 1000,
    ];

    expect($resolved['client'])->toBe('phpredis')
        ->and($resolved['options'])->toMatchArray([
            'cluster' => 'redis',
            'persistent' => false,
        ])->and($resolved['default'])->toBe($connectionDefaults)
        ->and($resolved['cache'])->toBe(array_replace($connectionDefaults, ['database' => '1']))
        ->and($resolved['password_present'])->toBeTrue()
        ->and($resolved['validated_default_algorithm'])->toBe(Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER)
        ->and($resolved['validated_cache_algorithm'])->toBe(Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER);
});

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

it('uses Laravel defaults without overriding Cloud managed resource values', function (): void {
    $resolved = resolvedCloudConfig();
    $resolvedWithCloudValues = resolvedCloudConfig([
        'DB_CONNECTION' => 'pgsql',
        'QUEUE_CONNECTION' => 'cloud',
        'FILESYSTEM_DISK' => 's3',
        'CACHE_STORE' => 'redis',
        'SESSION_DRIVER' => 'redis',
    ]);

    expect($resolved)->toMatchArray([
        'database.default' => 'sqlite',
        'database.redis.client' => 'phpredis',
        'queue.default' => 'database',
        'filesystems.default' => 'local',
        'cache.default' => 'database',
        'session.driver' => 'cookie',
    ])->and($resolvedWithCloudValues)->toMatchArray([
        'database.default' => 'pgsql',
        'database.redis.client' => 'phpredis',
        'queue.default' => 'cloud',
        'filesystems.default' => 's3',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
    ]);

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
    useManagedQueue();
    Storage::fake('smoke', ['driver' => 's3']);

    $this->artisan('reel:smoke')
        ->assertSuccessful()
        ->expectsOutputToContain('Reel is ready');

    expect(Storage::disk('smoke')->allFiles('reel/smoke'))->toBe([]);
});

it('fails readiness when the database has a pending migration', function (): void {
    config()->set('filesystems.default', 'smoke-pending-migration');
    useManagedQueue();
    Storage::fake('smoke-pending-migration', ['driver' => 's3']);
    DB::table('migrations')->where('migration', '2026_08_21_000001_harden_retention_concurrency')->delete();

    $this->artisan('reel:smoke')
        ->assertFailed()
        ->expectsOutputToContain('The database has pending migrations.');
});

it('fails readiness when S3 object storage is unwritable', function (): void {
    config()->set('filesystems.default', 'unwritable');
    useManagedQueue();
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
    useManagedQueue();
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
    $disk->shouldReceive('get')->andReturnUsing(function (string $path) use (&$objects): ?string {
        return $objects[$path] ?? null;
    });
    $disk->shouldReceive('exists')->andReturnUsing(function (string $path) use (&$objects): bool {
        return isset($objects[$path]);
    });
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
    useManagedQueue();
    $objects = [];
    $deleteAttempted = false;
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('getConfig')->once()->andReturn(['driver' => 's3']);
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$objects): bool {
        $objects[$path] = $contents;

        return true;
    });
    $disk->shouldReceive('get')->andReturnUsing(function (string $path) use (&$objects): ?string {
        return $objects[$path] ?? null;
    });
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
    useManagedQueue();
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

it('round-trips through the queue a managed connection actually provisions', function (): void {
    config()->set('filesystems.default', 'smoke-managed-queue');
    useManagedQueue('reel-queue-927aa415');
    Storage::fake('smoke-managed-queue', ['driver' => 's3']);

    $this->artisan('reel:smoke')
        ->assertSuccessful()
        ->expectsOutputToContain('Reel is ready');

    expect(DB::table('jobs')->count())->toBe(0);
});

it('names the connection and queue it worked when the round trip never completes', function (): void {
    config()->set('filesystems.default', 'smoke-stalled-queue');
    useManagedQueue('reel-queue-927aa415');
    $objects = [];
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('getConfig')->once()->andReturn(['driver' => 's3']);
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$objects): bool {
        // Keep the storage probe and swallow the queue's write, so the round trip never lands.
        $objects[$path] ??= $contents;

        return true;
    });
    $disk->shouldReceive('get')->andReturnUsing(function (string $path) use (&$objects): ?string {
        return $objects[$path] ?? null;
    });
    $disk->shouldReceive('exists')->andReturnUsing(function (string $path) use (&$objects): bool {
        return isset($objects[$path]);
    });
    $disk->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use (&$objects): bool {
        unset($objects[$path]);

        return true;
    });
    Storage::set('smoke-stalled-queue', $disk);

    $this->artisan('reel:smoke')
        ->assertFailed()
        ->expectsOutputToContain('The configured queue did not complete its smoke job [managed:reel-queue-927aa415].');
});

it('models a managed connection on which an invented queue name does not exist', function (): void {
    useManagedQueue('reel-queue-927aa415');

    expect(fn (): mixed => Queue::connection('managed')->push('job', '', 'reel-smoke'))
        ->toThrow(RuntimeException::class, 'Managed queue [reel-smoke] does not exist.');
});

// Deliberately the database driver: this is the branch where a queue name is a free string,
// so the probe keeps its own queue and `queue:work --once` cannot reach a customer job. Cloud
// readiness is proven above against the managed connection instead.
it('keeps the probe on an isolated queue when the driver creates queue names on demand', function (): void {
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
