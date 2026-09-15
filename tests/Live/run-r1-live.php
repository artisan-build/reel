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
const R1_MINIO_IMAGE = 'quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z';
const R1_NODE_BINARY = '/opt/homebrew/bin/node';
const R1_CHROME_BINARY = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const R1_BROWSER_RUNNER = 'tests/Live/run-r1-browser.mjs';
const R1_DIAGNOSTIC_MAX_BYTES = 4096;
const R1_DIAGNOSTIC_MAX_LINES = 40;
const R1_SERVER_LOG_READ_MAX_BYTES = 256 * 1024;
const R1_SERVER_ERROR_MAX_LINES = 20;
const R1_FOCUSED_TESTS = [
    'tests/Feature/ApplicationEnrollmentTest.php',
    'tests/Feature/ApplicationManagementTest.php',
    'tests/Feature/ManagedAuthIngressTest.php',
    'tests/Feature/RecordingChunkIngestTest.php',
    'tests/Feature/RetentionWorkflowTest.php',
];

/** @return never */
function r1Fail(string $message): void
{
    throw new RuntimeException($message);
}

/** @param array<string, string> $environment */
function r1Diagnostic(string $output, array $environment): string
{
    $output = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? '';
    $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace(["\r\n", "\r"], "\n", $output)) ?? '';

    foreach ($environment as $name => $value) {
        if ($value !== '' && preg_match('/(?:DATABASE|PORT|PASSWORD|SECRET|TOKEN|KEY(?:_ID)?|ROOT_USER)$/i', $name) === 1) {
            $output = str_replace($value, '[redacted]', $output);
        }
    }

    $output = preg_replace([
        '~(?:/[^/\s]+)*/reel-r1-live-[a-f0-9]+(?:/[^\s]*)?~',
        '/-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----/s',
        '/(?i)((?:--)?(?:password|secret|token|api[_-]?key|access[_-]?key(?:[_-]?id)?|private[_-]?key|root[_-]?user)\s*[=:]\s*)\S+/',
        '/\bbase64:[A-Za-z0-9+\/=]{32,}\b/',
        '/\bbfc_p6_[a-f0-9]{32}\b/',
        '/\breel-r1-(?:pg|redis|minio)-[a-f0-9]+\b/',
        '/\b127\.0\.0\.1:[0-9]{2,5}\b/',
    ], [
        '[redacted path]',
        '[redacted private key]',
        '$1[redacted]',
        '[redacted key]',
        '[redacted database]',
        '[redacted container]',
        '127.0.0.1:[redacted port]',
    ], $output) ?? '';

    $output = trim($output);
    if ($output === '') {
        return '[no output]';
    }

    $truncated = false;
    $lines = explode("\n", $output);
    if (count($lines) > R1_DIAGNOSTIC_MAX_LINES) {
        $lines = array_slice($lines, -R1_DIAGNOSTIC_MAX_LINES);
        $truncated = true;
    }
    $output = implode("\n", $lines);

    $marker = '[diagnostic truncated]... ';
    if (strlen($output) > R1_DIAGNOSTIC_MAX_BYTES - strlen($marker)) {
        $output = substr($output, -(R1_DIAGNOSTIC_MAX_BYTES - strlen($marker)));
        $truncated = true;
    }

    return $truncated ? $marker.ltrim($output) : $output;
}

function r1NewestServerError(string $log): string
{
    $recordPattern = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s+[A-Za-z0-9_.-]+\.ERROR:/m';
    if (preg_match_all($recordPattern, $log, $errors, PREG_OFFSET_CAPTURE) < 1) {
        return '';
    }

    /** @var array{0: string, 1: int} $newestError */
    $newestError = $errors[0][array_key_last($errors[0])];
    $record = substr($log, $newestError[1]);
    $nextRecordPattern = '/\n(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s+[A-Za-z0-9_.-]+\.[A-Z]+:)/';
    if (preg_match($nextRecordPattern, $record, $nextRecord, PREG_OFFSET_CAPTURE) === 1) {
        $record = substr($record, 0, $nextRecord[0][1]);
    }

    $leadingLines = implode("\n", array_slice(explode("\n", trim($record)), 0, R1_SERVER_ERROR_MAX_LINES));

    return substr($leadingLines, 0, R1_DIAGNOSTIC_MAX_BYTES);
}

/** @param array<string, string> $environment */
function r1ServerDiagnostic(
    string $applicationDirectory,
    array $environment,
    string $runDirectory,
): string {
    $environment['RUN_DIRECTORY_SECRET'] = $runDirectory;
    $logPath = $applicationDirectory.'/storage/logs/laravel.log';
    $logSize = is_file($logPath) ? filesize($logPath) : false;

    if (is_int($logSize) && $logSize > 0) {
        $readBytes = R1_SERVER_LOG_READ_MAX_BYTES;
        $log = file_get_contents($logPath, false, null, max(0, $logSize - $readBytes), $readBytes);
        if (is_string($log) && trim($log) !== '') {
            return r1Diagnostic(r1NewestServerError($log), $environment);
        }
    }

    return r1Diagnostic('', $environment);
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 */
function r1Run(array $command, string $directory, array $environment, string $label, int $timeout = 600): string
{
    $process = new Process($command, $directory, $environment, null, $timeout);

    if ($process->run() !== 0) {
        $diagnosticEnvironment = $environment;
        foreach ($command as $argument) {
            if (preg_match('/^(?:--)?([A-Z0-9_-]*(?:DATABASE|PORT|PASSWORD|SECRET|TOKEN|KEY(?:_ID)?|ROOT_USER))=(.+)$/i', $argument, $sensitive) === 1) {
                $diagnosticEnvironment['COMMAND_'.$sensitive[1]] = $sensitive[2];
            }
        }
        r1Fail(sprintf(
            "%s exited non-zero.\nstdout:\n%s\nstderr:\n%s",
            $label,
            r1Diagnostic($process->getOutput(), $diagnosticEnvironment),
            r1Diagnostic($process->getErrorOutput(), $diagnosticEnvironment),
        ));
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
    $environment = is_array($environment) ? array_filter($environment, is_string(...)) : [];

    return array_merge($environment, $overrides);
}

/** @return array<string, string> */
function r1RedisEnvironment(int $port, string $prefix): array
{
    return [
        'REDIS_CLIENT' => 'phpredis',
        'REDIS_URL' => 'null',
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_USERNAME' => 'null',
        'REDIS_PASSWORD' => 'null',
        'REDIS_PORT' => (string) $port,
        'REDIS_DB' => '0',
        'REDIS_CACHE_DB' => '1',
        'REDIS_CLUSTER' => 'redis',
        'REDIS_PREFIX' => $prefix.'redis-',
        'REDIS_PERSISTENT' => 'false',
        'REDIS_MAX_RETRIES' => '3',
        'REDIS_BACKOFF_ALGORITHM' => 'decorrelated_jitter',
        'REDIS_BACKOFF_BASE' => '100',
        'REDIS_BACKOFF_CAP' => '1000',
    ];
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

function r1PassedPestResult(string $output): bool
{
    try {
        $result = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }

    return is_array($result)
        && ! array_is_list($result)
        && ($result['tool'] ?? null) === 'pest'
        && ($result['result'] ?? null) === 'passed'
        && is_int($result['tests'] ?? null)
        && $result['tests'] > 0
        && is_int($result['passed'] ?? null)
        && $result['passed'] === $result['tests'];
}

/** @param array<string, string> $environment */
function r1FocusedTests(string $app, array $environment): array
{
    $output = r1Run(
        [PHP_BINARY, 'artisan', 'test', ...R1_FOCUSED_TESTS, '--stop-on-failure'],
        $app,
        $environment,
        'isolated R1 focused tests',
        1200,
    );

    if (! r1PassedPestResult($output)) {
        r1Fail('The focused test process returned no valid passing Pest result.');
    }

    return ['files' => R1_FOCUSED_TESTS, 'result' => 'pass'];
}

/**
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function r1BrowserState(string $app, array $environment, string $password): array
{
    $source = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$password = getenv('REEL_R1_SEED_PASSWORD');
if (! is_string($password) || $password === '') {
    throw new RuntimeException('Browser fixture password was unavailable.');
}

$owner = ArtisanBuild\BuiltForCloud\User::query()->where('email', 'r1-browser-owner@example.test')->sole();
$admin = ArtisanBuild\BuiltForCloud\User::query()->where('email', 'r1-browser-admin@example.test')->sole();
if ($owner->role !== ArtisanBuild\BuiltForCloud\UserRole::Owner->value
    || $admin->role !== ArtisanBuild\BuiltForCloud\UserRole::Admin->value) {
    throw new RuntimeException('Browser fixture administrative roles were incorrect.');
}
$owner->forceFill(['email_verified_at' => now()])->save();
$admin->forceFill(['email_verified_at' => now()])->save();

$member = new ArtisanBuild\BuiltForCloud\User;
$member->forceFill([
    'name' => 'R1 Browser Member',
    'email' => 'r1-browser-member@example.test',
    'email_verified_at' => now(),
    'password' => Illuminate\Support\Facades\Hash::make($password),
    'role' => ArtisanBuild\BuiltForCloud\UserRole::Member->value,
    'status' => 'active',
])->save();

$application = App\Models\Application::query()->create([
    'name' => 'R1 Browser Application',
    'allowed_origins' => ['https://browser.example.test'],
    'severity' => App\Enums\CaptureSeverity::Inputs,
    'mask_selectors' => [],
    'block_selectors' => [],
    'excluded_paths' => [],
    'sampling_percent' => 100,
    'ingest_enabled' => true,
    'max_new_sessions_per_day' => 1000,
    'max_concurrent_sessions' => 100,
    'max_chunks_per_session' => 360,
    'max_compressed_bytes_per_session' => 67108864,
    'max_compressed_chunk_bytes' => 262144,
    'max_daily_chunks' => 100000,
    'max_daily_compressed_bytes' => 10737418240,
    'max_ingest_requests_per_minute' => 600,
]);

$sessionId = bin2hex(random_bytes(32));
$recording = new App\Models\RecordingSession;
$recording->forceFill([
    'application_id' => $application->getKey(),
    'application_credential_id' => (string) Illuminate\Support\Str::uuid(),
    'session_id' => $sessionId,
    'grant_id_hash' => hash('sha256', $sessionId),
    'origin' => 'https://browser.example.test',
    'status' => App\Enums\RecordingSessionStatus::Ready,
    'protocol_version' => ArtisanBuild\ReelClient\Envelope::VERSION,
    'max_chunks' => 10,
    'max_compressed_bytes' => 1000000,
    'max_chunk_bytes' => 100000,
    'started_at' => now()->subMinutes(2),
    'max_event_time' => now()->subMinute(),
    'upload_cutoff_at' => now()->addMinute(),
    'ended_at' => now(),
    'maximum_expires_at' => now()->addDays(30),
    'expires_at' => now()->addDays(30),
    'delete_not_before' => now()->addDays(30),
    'status_changed_at' => now(),
    'is_complete' => true,
    'incomplete_reasons' => [],
    'initial_path' => '/browser-proof',
    'latest_path' => '/browser-proof',
    'duration_seconds' => 120,
])->save();

fwrite(STDOUT, json_encode([
    'application_path' => '/applications/'.$application->public_id,
    'session_path' => '/applications/'.$application->public_id.'/sessions/'.$sessionId,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
PHP;

    return r1Json(r1Run(
        [PHP_BINARY, '-r', $source],
        $app,
        [...$environment, 'REEL_R1_SEED_PASSWORD' => $password],
        'isolated browser fixture seed',
    ), 'Browser fixture seed');
}

function r1Clipboard(string $contents): void
{
    $process = new Process(['/usr/bin/pbcopy']);
    $process->setInput($contents);
    if ($process->run() !== 0) {
        r1Fail('The disposable browser clipboard could not be updated.');
    }
}

$root = dirname(__DIR__, 2);
$expectedCases = [
    'isolated_r1_focused_tests',
    'published_bfc_lock',
    'postgres_redis_minio_runtime',
    'local_scalpels_stub',
    'loopback_public_and_auth_denial',
    'local_bfc_cli',
    'standalone_chrome_browser',
    'queue_worker_and_object_round_trip',
    'scheduler_registration_and_tick',
];

if (in_array('--self-check', $argv, true)) {
    $source = (string) file_get_contents(__FILE__);
    $browserSource = (string) file_get_contents($root.'/'.R1_BROWSER_RUNNER);
    $requiredFocusedTests = [
        'tests/Feature/ApplicationEnrollmentTest.php',
        'tests/Feature/ApplicationManagementTest.php',
        'tests/Feature/ManagedAuthIngressTest.php',
        'tests/Feature/RecordingChunkIngestTest.php',
        'tests/Feature/RetentionWorkflowTest.php',
    ];
    if (R1_MINIO_IMAGE !== 'quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z') {
        r1Fail('The live runner self-check requires the pinned Quay MinIO image.');
    }
    if ($requiredFocusedTests !== R1_FOCUSED_TESTS) {
        r1Fail('The live runner self-check requires the complete R1 focused test selection.');
    }
    foreach ($requiredFocusedTests as $requiredFocusedTest) {
        if (! is_file($root.'/'.$requiredFocusedTest)) {
            r1Fail('The live runner self-check cannot find '.$requiredFocusedTest.'.');
        }
    }
    if (! r1PassedPestResult('{"tool":"pest","result":"passed","tests":9,"passed":9,"assertions":41}')) {
        r1Fail('The live runner self-check requires machine-readable Pest success recognition.');
    }
    foreach ([
        '',
        '{',
        '{"tool":"phpunit","result":"passed","tests":9,"passed":9}',
        '{"tool":"pest","result":"failed","tests":9,"passed":8}',
        '{"tool":"pest","result":"passed","tests":0,"passed":0}',
        '{"tool":"pest","result":"passed","tests":9,"passed":8}',
        '{"tool":"pest","result":"passed","tests":"9","passed":9}',
    ] as $invalidPestResult) {
        if (r1PassedPestResult($invalidPestResult)) {
            r1Fail('The live runner self-check requires fail-closed Pest result recognition.');
        }
    }
    $redisEnvironment = r1RedisEnvironment(16379, 'reel-r1-self-check-');
    if ($redisEnvironment !== [
        'REDIS_CLIENT' => 'phpredis',
        'REDIS_URL' => 'null',
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_USERNAME' => 'null',
        'REDIS_PASSWORD' => 'null',
        'REDIS_PORT' => '16379',
        'REDIS_DB' => '0',
        'REDIS_CACHE_DB' => '1',
        'REDIS_CLUSTER' => 'redis',
        'REDIS_PREFIX' => 'reel-r1-self-check-redis-',
        'REDIS_PERSISTENT' => 'false',
        'REDIS_MAX_RETRIES' => '3',
        'REDIS_BACKOFF_ALGORITHM' => 'decorrelated_jitter',
        'REDIS_BACKOFF_BASE' => '100',
        'REDIS_BACKOFF_CAP' => '1000',
    ]) {
        r1Fail('The live runner self-check requires the complete explicit disposable Redis environment.');
    }
    $diagnosticEnvironment = [
        'DB_DATABASE' => 'self_check_database_should_not_escape',
        'DB_PORT' => '65432',
        'AWS_SECRET_ACCESS_KEY' => 'self_check_secret_should_not_escape',
    ];
    try {
        r1Run([
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, str_repeat("o\\n", 80)."self-check-stdout\\ndatabase=self_check_database_should_not_escape\\n");'.
                'fwrite(STDERR, str_repeat("e", 8192)."\\nself-check-stderr\\npassword=self_check_secret_should_not_escape\\n".implode(" ", array_slice($argv, 1)));'.
                'exit(23);',
            '--',
            '--password=self_check_command_password_should_not_escape',
            'MINIO_ROOT_USER=self_check_root_user_should_not_escape',
        ], $root, $diagnosticEnvironment, 'diagnostic self-check');
        r1Fail('The diagnostic self-check process unexpectedly succeeded.');
    } catch (RuntimeException $exception) {
        $diagnostic = $exception->getMessage();
        if (preg_match('/stdout:\n(.*?)\nstderr:\n(.*)$/s', $diagnostic, $channels) !== 1
            || ! str_contains($channels[1], 'self-check-stdout')
            || ! str_contains($channels[2], 'self-check-stderr')
            || str_contains($diagnostic, 'self_check_database_should_not_escape')
            || str_contains($diagnostic, 'self_check_secret_should_not_escape')
            || str_contains($diagnostic, 'self_check_command_password_should_not_escape')
            || str_contains($diagnostic, 'self_check_root_user_should_not_escape')
            || ! str_contains($channels[1], '[diagnostic truncated]')
            || ! str_contains($channels[2], '[diagnostic truncated]')
            || strlen($channels[1]) > R1_DIAGNOSTIC_MAX_BYTES
            || strlen($channels[2]) > R1_DIAGNOSTIC_MAX_BYTES
            || substr_count($channels[1], "\n") >= R1_DIAGNOSTIC_MAX_LINES
            || substr_count($channels[2], "\n") >= R1_DIAGNOSTIC_MAX_LINES) {
            r1Fail('The live runner self-check could not prove bounded redacted diagnostics for both output channels.');
        }
    }
    $serverLog = "[2026-09-15 11:59:59] production.ERROR: OlderSelfCheckException\n#0 older frame\n".
        "[2026-09-15 12:00:00] production.ERROR: NewestSelfCheckException\n".
        "/tmp/reel-r1-live-deadbeef/candidate/storage/logs/laravel.log\n".
        implode("\n", array_map(static fn (int $frame): string => "#{$frame} newest frame", range(0, 80)))."\n".
        "[2026-09-15 12:00:01] production.INFO: LaterSelfCheckRecord\n";
    $serverDiagnostic = r1Diagnostic(
        r1NewestServerError($serverLog),
        [],
    );
    if (! str_contains($serverDiagnostic, 'NewestSelfCheckException')
        || ! str_contains($serverDiagnostic, '#0 newest frame')
        || str_contains($serverDiagnostic, 'OlderSelfCheckException')
        || str_contains($serverDiagnostic, '#80 newest frame')
        || str_contains($serverDiagnostic, 'LaterSelfCheckRecord')
        || str_contains($serverDiagnostic, 'reel-r1-live-deadbeef')
        || strlen($serverDiagnostic) > R1_DIAGNOSTIC_MAX_BYTES
        || substr_count($serverDiagnostic, "\n") >= R1_DIAGNOSTIC_MAX_LINES) {
        r1Fail('The live runner self-check could not prove bounded newest-error selection and redaction.');
    }
    if (r1Diagnostic(r1NewestServerError('[2026-09-15 12:00:02] production.INFO: No error'), []) !== '[no output]') {
        r1Fail('The live runner self-check requires invalid server diagnostics to fail closed.');
    }
    foreach ([
        'function r1ServerDiagnostic(',
        'function r1NewestServerError(',
        "'/storage/logs/laravel.log'",
        'R1_SERVER_LOG_READ_MAX_BYTES',
        'R1_SERVER_ERROR_MAX_LINES',
        'r1ServerDiagnostic($app, $environment, $runDirectory)',
        'server diagnostic:',
    ] as $requiredServerDiagnosticSource) {
        if (! str_contains($source, $requiredServerDiagnosticSource)) {
            r1Fail('The live runner self-check requires the bounded sanitized server-error diagnostic path.');
        }
    }
    foreach (['create-admin', '--local', 'reel:smoke', 'schedule:run', '127.0.0.1', 'finally'] as $required) {
        if (! str_contains($source, $required)) {
            r1Fail('The live runner self-check is missing '.$required.'.');
        }
    }
    if (R1_NODE_BINARY !== '/opt/homebrew/bin/node'
        || R1_CHROME_BINARY !== '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
        || ! is_executable(R1_NODE_BINARY)
        || ! is_executable(R1_CHROME_BINARY)
        || ! is_file($root.'/'.R1_BROWSER_RUNNER)
        || ! str_contains($browserSource, R1_CHROME_BINARY)) {
        r1Fail('The live runner self-check requires the verified Node, Chrome, and browser runner paths.');
    }
    foreach ([
        "role: 'owner'",
        "role: 'admin'",
        "role: 'member'",
        '/usr/bin/pbpaste',
        '/usr/bin/pbcopy',
        'clearClipboard();',
        'browser-owner.png',
        'browser-admin.png',
        'browser-member.png',
        'browser-evidence.json',
        'application-signing-credentials',
        'retention-controls',
        'secrets_recorded: false',
        'verifier_blocked: []',
        'rmSync(profile',
        'navigationDiagnosticMaxBytes',
        'NavigationDiagnosticError',
        'Buffer.byteLength(diagnostic',
        'sanitizePath(path)',
        '.split(/[?#]/, 1)',
        "message.method === 'Network.responseReceived'",
        "message.method === 'Network.loadingFailed'",
        'expected_path:',
        'current_path:',
        'document_ready_state:',
        'main_document_status:',
        'expected_marker_present:',
        'error_marker_present:',
        'login_marker_present:',
        'document_request_failed:',
        'cdp_socket_open:',
        'chrome_running:',
        'process.stderr.write(`${failure.message}\\n`)',
    ] as $requiredBrowserSource) {
        if (! str_contains($browserSource, $requiredBrowserSource)) {
            r1Fail('The live runner self-check is missing the required browser lane structure.');
        }
    }
    foreach (['docker run', 'P6LoopbackProcess::start', 'r1FocusedTests'] as $forbiddenBrowserSource) {
        if (str_contains($browserSource, $forbiddenBrowserSource)) {
            r1Fail('The browser runner must not start the full live stack.');
        }
    }

    fwrite(STDOUT, json_encode([
        'schema' => 'reel.r1.live.v1',
        'mode' => 'self-check',
        'cases' => $expectedCases,
        'browser' => 'standalone-chrome-self-check',
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
$browserEvidence = [];
$browserArtifacts = [];
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
        ...r1RedisEnvironment($redisPort, $prefix),
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
    if (($package['version'] ?? null) !== 'v0.13.2'
        || ($package['source']['reference'] ?? null) !== '7a8169c544d5227b244950e1eb13c331d9f6f96e') {
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
    $browserPassword = bin2hex(random_bytes(24)).'Aa1!';
    r1Run([
        PHP_BINARY, 'artisan', 'create-admin', '--execute', '--local',
        '--email=r1-browser-owner@example.test', '--password='.$browserPassword,
        '--name=R1 Browser Owner', '--no-interaction',
    ], $app, $environment, 'local BfC owner creation');
    r1Run([
        PHP_BINARY, 'artisan', 'create-admin', '--execute', '--local', '--force',
        '--email=r1-browser-admin@example.test', '--password='.$browserPassword,
        '--name=R1 Browser Admin', '--no-interaction',
    ], $app, $environment, 'local BfC admin creation');
    $cases['local_bfc_cli'] = 'pass';

    $browserState = r1BrowserState($app, $environment, $browserPassword);
    $browserArtifactDirectory = $runDirectory.'/browser-artifacts';
    mkdir($browserArtifactDirectory, 0700);
    r1Clipboard($browserPassword);
    try {
        r1Run([
            R1_NODE_BINARY,
            $app.'/'.R1_BROWSER_RUNNER,
        ], $app, [...$environment,
            'REEL_R1_BROWSER_BASE_URL' => 'http://127.0.0.1:'.$appListener->port,
            'REEL_R1_BROWSER_ARTIFACT_DIR' => $browserArtifactDirectory,
            'REEL_R1_BROWSER_CANDIDATE_SHA' => $candidateSha,
            'REEL_R1_BROWSER_APPLICATION_PATH' => (string) ($browserState['application_path'] ?? ''),
            'REEL_R1_BROWSER_SESSION_PATH' => (string) ($browserState['session_path'] ?? ''),
        ], 'standalone Chrome browser lane', 300);
    } catch (Throwable $browserFailure) {
        throw new RuntimeException(
            $browserFailure->getMessage()."\nserver diagnostic:\n".
            r1ServerDiagnostic($app, $environment, $runDirectory),
            0,
            $browserFailure,
        );
    } finally {
        $browserPassword = '';
        r1Clipboard('');
    }
    $browserEvidence = r1Json(
        (string) file_get_contents($browserArtifactDirectory.'/browser-evidence.json'),
        'Browser evidence',
    );
    $browserCases = $browserEvidence['cases'] ?? null;
    $expectedScreenshots = ['browser-owner.png', 'browser-admin.png', 'browser-member.png'];
    if (($browserEvidence['candidate_sha'] ?? null) !== $candidateSha
        || ($browserEvidence['secrets_recorded'] ?? null) !== false
        || ($browserEvidence['verifier_blocked'] ?? null) !== []
        || ! is_array($browserCases)
        || array_keys($browserCases) !== ['owner', 'admin', 'member']
        || ($browserEvidence['cleanup'] ?? null) !== [
            'clipboard_cleared' => true,
            'chrome_stopped' => true,
            'profile_removed' => true,
        ]) {
        r1Fail('The standalone Chrome browser evidence was incomplete.');
    }
    foreach (['browser-evidence.json', ...$expectedScreenshots] as $browserArtifact) {
        $contents = file_get_contents($browserArtifactDirectory.'/'.$browserArtifact);
        if (! is_string($contents) || $contents === '') {
            r1Fail('A required browser artifact was absent.');
        }
        $browserArtifacts[$browserArtifact] = $contents;
    }
    $cases['standalone_chrome_browser'] = 'pass';

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
        r1Clipboard('');
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
$browserOutputDirectory = dirname($stampPath).'/browser';
if (file_exists($browserOutputDirectory) || ! mkdir($browserOutputDirectory, 0700)) {
    fwrite(STDERR, "Reel R1 browser artifact directory could not be created.\n");
    exit(1);
}
foreach ($browserArtifacts as $name => $contents) {
    file_put_contents($browserOutputDirectory.'/'.$name, $contents);
}
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
        'verdict' => 'pass',
        'runner' => R1_BROWSER_RUNNER,
        'artifact_directory' => 'browser',
        'browser' => $browserEvidence['browser'] ?? null,
        'roles' => array_keys($browserCases),
        'verifier_blocked' => $browserEvidence['verifier_blocked'] ?? null,
        'cleanup' => $browserEvidence['cleanup'] ?? null,
        'secrets_recorded' => $browserEvidence['secrets_recorded'] ?? null,
    ],
    'teardown' => $teardown,
];
file_put_contents($stampPath, json_encode($stamp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, json_encode([
    'candidate_sha' => $candidateSha,
    'cases' => count($cases),
    'teardown' => 'pass',
    'browser' => 'pass',
    'stamp' => $stampPath,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
