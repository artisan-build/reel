<?php

namespace App\Console\Commands;

use App\Jobs\CloudSmokeRoundTrip;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class SmokeCloudDeployment extends Command
{
    private const string SMOKE_QUEUE = 'reel-smoke';

    /** @var string */
    protected $signature = 'reel:smoke';

    /** @var string */
    protected $description = 'Verify the configured database, object storage, queue, and scheduler';

    public function handle(): int
    {
        $path = 'reel/smoke/'.Str::uuid().'.txt';
        $probe = 'storage-'.Str::random(32);
        $roundTrip = 'queue-'.Str::random(32);
        $disk = null;
        $failed = false;

        try {
            $disk = Storage::disk((string) config('filesystems.default'));
            $this->verifyCloudDrivers($disk);
            $this->verifyDatabase();
            $this->verifyStorage($disk, $path, $probe);
            $this->verifyQueue($disk, $path, $probe, $roundTrip);
            $this->verifyScheduler();
        } catch (Throwable $exception) {
            $failed = true;
            $this->components->error($exception->getMessage());
        } finally {
            if ($disk instanceof FilesystemAdapter) {
                try {
                    if (! $disk->delete($path)) {
                        throw new RuntimeException('Object storage cleanup failed.');
                    }

                    if ($disk->exists($path)) {
                        throw new RuntimeException('Object storage cleanup failed.');
                    }
                } catch (Throwable $exception) {
                    $failed = true;
                    $this->components->error($exception->getMessage());
                }
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->components->info('Reel is ready: database, object storage, queue, and scheduler checks passed.');

        return self::SUCCESS;
    }

    private function verifyDatabase(): void
    {
        DB::connection()->select('SELECT 1');

        if (Artisan::call('migrate:status', ['--pending' => self::FAILURE]) !== self::SUCCESS) {
            throw new RuntimeException('The database has pending migrations.');
        }

    }

    private function verifyCloudDrivers(FilesystemAdapter $disk): void
    {
        $filesystemDriver = (string) ($disk->getConfig()['driver'] ?? 'unknown');

        if ($filesystemDriver !== 's3') {
            throw new RuntimeException("The configured filesystem driver [{$filesystemDriver}] is not S3-backed. Attach Laravel Object Storage and remove any FILESYSTEM_DISK override.");
        }

        $queueConnection = (string) config('queue.default');
        $queueDriver = (string) config("queue.connections.{$queueConnection}.driver", 'unknown');
        $queue = Queue::connection($queueConnection);

        if ($queue instanceof SyncQueue || in_array($queueDriver, ['sync', 'deferred', 'background', 'null'], true)) {
            throw new RuntimeException("The configured queue driver [{$queueDriver}] is inline. Attach a managed queue and remove any QUEUE_CONNECTION override.");
        }
    }

    private function verifyStorage(FilesystemAdapter $disk, string $path, string $probe): void
    {
        if (! $disk->put($path, $probe) || $disk->get($path) !== $probe) {
            throw new RuntimeException('Object storage is not writable and readable.');
        }
    }

    private function verifyQueue(FilesystemAdapter $disk, string $path, string $probe, string $roundTrip): void
    {
        dispatch(new CloudSmokeRoundTrip($path, $probe, $roundTrip, now()->addMinute()->getTimestamp()))
            ->onQueue(self::SMOKE_QUEUE);

        for ($attempt = 0; $attempt < 5 && $disk->get($path) !== $roundTrip; $attempt++) {
            Artisan::call('queue:work', [
                'connection' => (string) config('queue.default'),
                '--queue' => self::SMOKE_QUEUE,
                '--once' => true,
                '--sleep' => 0,
                '--tries' => 1,
            ]);
        }

        if ($disk->get($path) !== $roundTrip) {
            throw new RuntimeException('The configured queue did not complete its smoke job.');
        }
    }

    private function verifyScheduler(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('built-for-cloud.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $process = new Process([PHP_BINARY, 'artisan', 'schedule:list', '--json'], base_path());
        $process->mustRun();
        /** @var list<array{command: string, expression: string}> $events */
        $events = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $registered = collect($events)
            ->filter(fn (array $event): bool => str_starts_with($event['command'], 'php artisan reel:'))
            ->map(fn (array $event): array => [
                'command' => Str::after($event['command'], 'php artisan '),
                'expression' => $event['expression'],
            ])
            ->values()
            ->all();

        if ($registered !== $manifest['scheduler']) {
            throw new RuntimeException('The registered Reel scheduler does not match the deployment manifest.');
        }
    }
}
