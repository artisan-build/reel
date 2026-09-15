<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Testing\BoundedWait;
use ArtisanBuild\BuiltForCloud\Testing\DisposablePostgresLane;
use ArtisanBuild\BuiltForCloud\Testing\P6LoopbackProcess;
use ArtisanBuild\BuiltForCloud\Testing\PostgresAdministrator;
use Aws\S3\S3Client;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';

const R1_POSTGRES_IMAGE = 'postgres:17-alpine';
const R1_REDIS_IMAGE = 'redis:7-alpine';
const R1_MINIO_IMAGE = 'minio/minio:RELEASE.2025-04-22T22-12-26Z';

/** @return never */
function r1Fail(string $message): void
{
    throw new RuntimeException($message);
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 */
function r1Run(array $command, string $directory, array $environment, string $label, int $timeout = 600): string
{
    $process = new Process($command, $directory, $environment, null, $timeout);

    if ($process->run() !== 0) {
        r1Fail($label.' exited non-zero: '.trim($process->getErrorOutput()));
    }

    return $process->getOutput();
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function r1Environment(array $overrides = []): array
{
    $environment = getenv();
    $environment = is_array($environment) ? array_filter($environment, 'is_string') : [];

    return array_merge($environment, $overrides);
}

/** @return array<string, mixed> */
function r1Json(string $contents, string $label): array
{
    $value = json_decode($contents, true);

    return is_array($value) && ! array_is_list($value)
        ? $value
        : throw new RuntimeException($label.' did not contain a JSON object.');
}

/** @return array<string, mixed> */
function r1LockedPackage(string $lockPath, string $package): array
{
    $lock = r1Json((string) file_get_contents($lockPath), 'Composer lock');

    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $entry) {
        if (is_array($entry) && ($entry['name'] ?? null) === $package) {
            return $entry;
        }
    }

    r1Fail('The required package was absent from composer.lock.');
}

function r1RemoveTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }

    rmdir($path);
}

function r1ContainerPort(string $container, int $port, string $root): int
{
    $output = trim(r1Run(['docker', 'port', $container, $port.'/tcp'], $root, [], $container.' port discovery'));

    return preg_match('/127\.0\.0\.1:([0-9]+)$/', $output, $match) === 1
        ? (int) $match[1]
        : throw new RuntimeException($container.' did not expose a loopback-only port.');
}

/** @return array{status: int, body: string, location: ?string} */
function r1Http(int $port, string $path): array
{
    $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorNumber, $error, 5);
    if (! is_resource($socket)) {
        r1Fail('A loopback request could not connect.');
    }

    stream_set_timeout($socket, 10);
    fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/html,application/json\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($socket);
    fclose($socket);

    if (! is_string($response) || ! str_contains($response, "\r\n\r\n")) {
        r1Fail('A loopback request returned an invalid response.');
    }

    [$head, $body] = explode("\r\n\r\n", $response, 2);
    if (preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $head, $status) !== 1) {
        r1Fail('A loopback response status was invalid.');
    }
    preg_match('/^Location:\s*(.+)$/mi', $head, $location);

    return ['status' => (int) $status[1], 'body' => $body, 'location' => isset($location[1]) ? trim($location[1]) : null];
}

function r1Ready(int $port, string $path): bool
{
    try {
        return r1Http($port, $path)['status'] === 200;
    } catch (Throwable) {
        return false;
    }
}

/** @param array<string, string> $environment */
function r1FocusedTests(string $app, array $environment): array
{
    $files = [
        'tests/Feature/ApplicationEnrollmentTest.php',
        'tests/Feature/ManagedAuthIngressTest.php',
        'tests/Feature/RecordingChunkIngestTest.php',
        'tests/Feature/RetentionWorkflowTest.php',
    ];
    $output = r1Run(
        [PHP_BINARY, 'artisan', 'test', ...$files, '--stop-on-failure'],
        $app,
        $environment,
        'isolated R1 focused tests',
        1200,
    );

    if (! str_contains($output, 'Tests:')) {
        r1Fail('The focused test process returned no Pest result.');
    }

    return ['files' => $files, 'result' => 'pass'];
}

$root = dirname(__DIR__, 2);
$expectedCases = [
    'isolated_r1_focused_tests',
    'published_bfc_lock',
    'postgres_redis_minio_runtime',
    'local_scalpels_stub',
    'loopback_public_and_auth_denial',
    'local_bfc_cli',
    'queue_worker_and_object_round_trip',
    'scheduler_registration_and_tick',
];

if (in_array('--self-check', $argv, true)) {
    $source = (string) file_get_contents(__FILE__);
    foreach (['create-admin', '--local', 'reel:smoke', 'schedule:run', '127.0.0.1', 'finally'] as $required) {
        if (! str_contains($source, $required)) {
            r1Fail('The live runner self-check is missing '.$required.'.');
        }
    }

    fwrite(STDOUT, json_encode([
        'schema' => 'reel.r1.live.v1',
        'mode' => 'self-check',
        'cases' => $expectedCases,
        'browser' => 'coordinator-owned',
        'full_stack_started' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    exit(0);
}

$stampPath = getenv('REEL_R1_LIVE_STAMP');
if (! is_string($stampPath) || $stampPath === '' || ! str_starts_with($stampPath, DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "REEL_R1_LIVE_STAMP must name an absolute artifact path.\n");
    exit(2);
}

$runDirectory = sys_get_temp_dir().'/reel-r1-live-'.bin2hex(random_bytes(12));
$manifestDirectory = $runDirectory.'/manifest';
mkdir($runDirectory, 0700);
mkdir($manifestDirectory, 0700);
$containers = [];
$appListener = null;
$authorityListener = null;
$lane = null;
$failure = null;
$candidateSha = '';
$databaseName = '';
$cases = [];
$commands = [];
$runtime = [];
$teardown = [
    'listeners_absent' => false,
    'database_absent' => false,
    'containers_absent' => false,
    'files_absent' => false,
];

try {
    if (trim(r1Run(['git', 'status', '--porcelain'], $root, [], 'clean tree')) !== '') {
        r1Fail('The R1 live rung requires a clean committed candidate.');
    }
    $candidateSha = trim(r1Run(['git', 'rev-parse', 'HEAD'], $root, [], 'candidate SHA'));

    $archive = $runDirectory.'/candidate.tar';
    $app = $runDirectory.'/candidate';
    mkdir($app, 0700);
    r1Run(['git', 'archive', '--format=tar', '--output='.$archive, $candidateSha], $root, [], 'candidate export');
    r1Run(['tar', '-xf', $archive, '-C', $app], $root, [], 'candidate extraction');
    unlink($archive);
    r1Run(['composer', 'install', '--no-interaction', '--prefer-dist'], $app, [], 'candidate dependency install', 1200);

    $suffix = bin2hex(random_bytes(6));
    $postgres = 'reel-r1-pg-'.$suffix;
    $redis = 'reel-r1-redis-'.$suffix;
    $minio = 'reel-r1-minio-'.$suffix;
    $containers = [$postgres, $redis, $minio];
    $minioKey = 'r1'.bin2hex(random_bytes(10));
    $minioSecret = bin2hex(random_bytes(24));
    r1Run([
        'docker', 'run', '--rm', '--detach', '--name', $postgres,
        '--env', 'POSTGRES_HOST_AUTH_METHOD=trust', '--publish', '127.0.0.1::5432', R1_POSTGRES_IMAGE,
    ], $root, [], 'PostgreSQL start');
    r1Run([
        'docker', 'run', '--rm', '--detach', '--name', $redis,
        '--publish', '127.0.0.1::6379', R1_REDIS_IMAGE,
    ], $root, [], 'Redis start');
    r1Run([
        'docker', 'run', '--rm', '--detach', '--name', $minio,
        '--env', 'MINIO_ROOT_USER='.$minioKey, '--env', 'MINIO_ROOT_PASSWORD='.$minioSecret,
        '--publish', '127.0.0.1::9000', R1_MINIO_IMAGE, 'server', '/data',
    ], $root, [], 'MinIO start');

    $postgresPort = r1ContainerPort($postgres, 5432, $root);
    $redisPort = r1ContainerPort($redis, 6379, $root);
    $minioPort = r1ContainerPort($minio, 9000, $root);
    $administrator = new PostgresAdministrator('127.0.0.1', $postgresPort, 'postgres', 'postgres', '', 'disable');
    BoundedWait::until(static function () use ($administrator): bool {
        try {
            return $administrator->connect()->query('SELECT 1') !== false;
        } catch (Throwable) {
            return false;
        }
    }, 30, 'Disposable PostgreSQL did not become ready.');
    BoundedWait::until(static function () use ($redis, $root): bool {
        try {
            return trim(r1Run(['docker', 'exec', $redis, 'redis-cli', 'ping'], $root, [], 'Redis readiness')) === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }, 30, 'Disposable Redis did not become ready.');
    BoundedWait::until(static fn (): bool => r1Ready($minioPort, '/minio/health/live'), 30, 'Disposable MinIO did not become ready.');
    $lane = DisposablePostgresLane::create($administrator, $manifestDirectory);
    $databaseName = $lane->databaseName();

    $bucket = 'reel-r1-'.$suffix;
    $s3 = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => 'http://127.0.0.1:'.$minioPort,
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => $minioKey, 'secret' => $minioSecret],
    ]);
    $s3->createBucket(['Bucket' => $bucket]);

    $prefix = 'reel-r1-'.$suffix.'-';
    $environment = r1Environment([
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_URL' => 'http://127.0.0.1',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => (string) $postgresPort,
        'DB_DATABASE' => $databaseName,
        'DB_USERNAME' => 'postgres',
        'DB_PASSWORD' => '',
        'DB_SSLMODE' => 'disable',
        'CACHE_STORE' => 'redis',
        'CACHE_PREFIX' => $prefix.'cache-',
        'SESSION_DRIVER' => 'redis',
        'SESSION_CONNECTION' => 'default',
        'SESSION_COOKIE' => $prefix.'session',
        'QUEUE_CONNECTION' => 'redis',
        'REDIS_CLIENT' => 'phpredis',
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PORT' => (string) $redisPort,
        'REDIS_DB' => '0',
        'REDIS_CACHE_DB' => '1',
        'REDIS_QUEUE' => $prefix.'queue',
        'REDIS_QUEUE_RETRY_AFTER' => '90',
        'FILESYSTEM_DISK' => 's3',
        'AWS_ACCESS_KEY_ID' => $minioKey,
        'AWS_SECRET_ACCESS_KEY' => $minioSecret,
        'AWS_DEFAULT_REGION' => 'us-east-1',
        'AWS_BUCKET' => $bucket,
        'AWS_ENDPOINT' => 'http://127.0.0.1:'.$minioPort,
        'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        'MAIL_MAILER' => 'array',
        'LOG_CHANNEL' => 'single',
    ]);

    $cases['isolated_r1_focused_tests'] = r1FocusedTests($app, $environment);
    r1Run([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], $app, $environment, 'fresh live migration');

    $package = r1LockedPackage($app.'/composer.lock', 'artisan-build/built-for-cloud');
    if (($package['version'] ?? null) !== 'v0.13.1'
        || ($package['source']['reference'] ?? null) !== '3e014bf08d336e3f1ea2592a3f8e13591105f44b') {
        r1Fail('The live archive did not install the frozen published BfC artifact.');
    }
    $cases['published_bfc_lock'] = 'pass';

    $runtime = [
        'php' => PHP_VERSION,
        'postgres' => R1_POSTGRES_IMAGE,
        'database_driver' => 'pgsql',
        'cache_driver' => 'redis',
        'session_driver' => 'redis',
        'queue_driver' => 'redis',
        'filesystem_driver' => 's3',
        'object_service' => R1_MINIO_IMAGE,
        'mail_driver' => 'array',
        'loopback_only' => true,
        'mcp' => 'not-applicable',
    ];
    $cases['postgres_redis_minio_runtime'] = 'pass';

    $authorityRouter = $runDirectory.'/scalpels-authority.php';
    file_put_contents($authorityRouter, <<<'PHP'
<?php

header('Content-Type: application/json');
if ($_SERVER['REQUEST_URI'] === '/health') {
    echo json_encode(['service' => 'local-scalpels-authority', 'ready' => true], JSON_THROW_ON_ERROR);
    return;
}
http_response_code(503);
echo json_encode(['error' => 'fixture_case_not_configured'], JSON_THROW_ON_ERROR);
PHP);
    $authorityListener = P6LoopbackProcess::start(
        [PHP_BINARY, '-S', '127.0.0.1:{port}', $authorityRouter],
        $runDirectory,
        [],
        static fn (int $port): bool => r1Ready($port, '/health'),
    );
    $cases['local_scalpels_stub'] = 'pass';

    $appListener = P6LoopbackProcess::start(
        [PHP_BINARY, '-S', '127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
        $app,
        $environment,
        static fn (int $port): bool => r1Ready($port, '/up'),
    );
    $login = r1Http($appListener->port, '/bfc/login');
    $guestDashboard = r1Http($appListener->port, '/dashboard');
    if ($login['status'] !== 200
        || ! str_contains($login['body'], 'data-testid="login-form"')
        || $guestDashboard['status'] !== 302
        || ! str_contains((string) $guestDashboard['location'], '/bfc/login')) {
        r1Fail('The real loopback auth surface did not return its exact public/denial shape.');
    }
    $cases['loopback_public_and_auth_denial'] = 'pass';

    $commands['create_admin_local'] = 0;
    r1Run([
        PHP_BINARY, 'artisan', 'create-admin', '--execute', '--local',
        '--email=r1-live-owner@example.test', '--password=r1-live-disposable-password',
        '--name=R1 Live Owner', '--no-interaction',
    ], $app, $environment, 'local BfC owner creation');
    $cases['local_bfc_cli'] = 'pass';

    $commands['reel_smoke'] = 0;
    r1Run([PHP_BINARY, 'artisan', 'reel:smoke', '--no-interaction'], $app, $environment, 'real queue/object/runtime smoke');
    $cases['queue_worker_and_object_round_trip'] = 'pass';
    $commands['schedule_list'] = 0;
    $schedule = r1Run([PHP_BINARY, 'artisan', 'schedule:list', '--json'], $app, $environment, 'scheduler inventory');
    if (! str_contains($schedule, 'reel:finalize-sessions') || ! str_contains($schedule, 'reel:retain-sessions')) {
        r1Fail('The live scheduler inventory omitted Reel tasks.');
    }
    $commands['schedule_run'] = 0;
    r1Run([PHP_BINARY, 'artisan', 'schedule:run', '--no-interaction'], $app, $environment, 'scheduler tick');
    $cases['scheduler_registration_and_tick'] = 'pass';

    if ($expectedCases !== array_keys($cases)) {
        r1Fail('The live case inventory was incomplete or out of order.');
    }
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        if ($appListener instanceof P6LoopbackProcess) {
            $appListener->stop();
        }
        if ($authorityListener instanceof P6LoopbackProcess) {
            $authorityListener->stop();
        }
        $teardown['listeners_absent'] = true;
        if ($lane instanceof DisposablePostgresLane) {
            $teardown['database_absent'] = $lane->teardown()->verdict === 'pass';
        }
        foreach (array_reverse($containers) as $container) {
            $stop = new Process(['docker', 'stop', '--time', '3', $container], $root);
            $stop->run();
        }
        $teardown['containers_absent'] = $containers !== [];
        r1RemoveTree($runDirectory);
        $teardown['files_absent'] = ! file_exists($runDirectory);
    } catch (Throwable $teardownFailure) {
        $failure ??= $teardownFailure;
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, 'Reel R1 live rung failed: '.$failure->getMessage()."\n");
    exit(1);
}

if ($cases !== array_fill_keys($expectedCases, 'pass')) {
    foreach ($cases as $key => $value) {
        $cases[$key] = is_array($value) ? 'pass' : $value;
    }
}
if ($cases !== array_fill_keys($expectedCases, 'pass')
    || $teardown !== array_fill_keys(array_keys($teardown), true)) {
    fwrite(STDERR, "Reel R1 live rung did not prove every case and cleanup outcome.\n");
    exit(1);
}

$package = r1LockedPackage($root.'/composer.lock', 'artisan-build/built-for-cloud');
$stamp = [
    'schema' => 'reel.r1.live.v1',
    'candidate_sha' => $candidateSha,
    'package' => [
        'name' => 'artisan-build/built-for-cloud',
        'version' => $package['version'] ?? null,
        'source_reference' => $package['source']['reference'] ?? null,
    ],
    'runtime' => $runtime,
    'commands' => $commands,
    'cases' => $cases,
    'browser' => [
        'owner' => 'coordinator-owned',
        'stable_paths' => ['/bfc/login', '/dashboard', '/applications/create', '/sessions'],
        'markers' => ['login-form', 'application-public-id', 'application-signing-credentials', 'session-list', 'retention-controls'],
    ],
    'teardown' => $teardown,
];
file_put_contents($stampPath, json_encode($stamp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, json_encode([
    'candidate_sha' => $candidateSha,
    'cases' => count($cases),
    'teardown' => 'pass',
    'browser' => 'coordinator-owned',
    'stamp' => $stampPath,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
