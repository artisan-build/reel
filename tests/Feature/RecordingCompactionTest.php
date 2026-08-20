<?php

declare(strict_types=1);

use App\Enums\RecordingEpochStatus;
use App\Enums\RecordingSessionStatus;
use App\Events\CompactionCandidateVerified;
use App\Events\CompactionCandidateWritten;
use App\Events\CompactionPublished;
use App\Jobs\CleanupCompactionCandidate;
use App\Jobs\CompactRecordingSession;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Models\RecordingEpoch;
use App\Models\RecordingSession;
use App\Services\OperationalCounters;
use App\Services\RecordingCompactor;
use App\Services\ReplayManifest;
use App\Services\SessionFinalizer;
use ArtisanBuild\ReelClient\Envelope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<array{epoch: string, sequence: int, label: string}>  $chunks
 */
function createCompactionFixture(
    array $chunks = [
        ['epoch' => 'epoch-1', 'sequence' => 0, 'label' => 'first'],
    ],
    RecordingSessionStatus $status = RecordingSessionStatus::Compacting,
): RecordingSession {
    $application = Application::factory()->create();
    $credential = ApplicationCredential::factory()->for($application)->create();
    $session = new RecordingSession;
    $session->fill([
        'application_id' => $application->getKey(),
        'application_credential_id' => $credential->getKey(),
        'session_id' => bin2hex(random_bytes(32)),
        'grant_id_hash' => hash('sha256', 'compaction-fixture'),
        'origin' => 'https://monitored.example',
        'protocol_version' => Envelope::VERSION,
        'max_chunks' => 100,
        'max_compressed_bytes' => 10_000_000,
        'max_chunk_bytes' => 1_000_000,
        'chunk_count' => count($chunks),
        'compressed_bytes' => 0,
        'epoch_count' => count(array_unique(array_column($chunks, 'epoch'))),
        'started_at' => now()->subMinute(),
        'max_event_time' => now()->addMinutes(29),
        'upload_cutoff_at' => now()->addMinutes(30),
        'maximum_expires_at' => now()->addDays(30),
        'status_changed_at' => now(),
        'is_complete' => true,
        'incomplete_reasons' => [],
        'gap_count' => 0,
    ]);
    $session->status = $status;
    $session->save();

    foreach (collect($chunks)->groupBy('epoch') as $epochId => $epochChunks) {
        $epoch = new RecordingEpoch;
        $epoch->fill([
            'recording_session_id' => $session->getKey(),
            'epoch_id' => $epochId,
            'terminal_sequence' => (int) $epochChunks->max('sequence'),
        ]);
        $epoch->status = RecordingEpochStatus::Active;
        $epoch->save();
    }

    $compressedBytes = 0;

    foreach ($chunks as $index => $fixture) {
        $timestamp = now()->getTimestampMs() + $index;
        $payload = gzencode(json_encode([[
            'type' => 5,
            'timestamp' => $timestamp,
            'data' => ['tag' => $fixture['label']],
        ]], JSON_THROW_ON_ERROR));

        if ($payload === false) {
            throw new RuntimeException('Unable to encode the compaction fixture.');
        }

        $key = implode('/', [
            'reel/chunks',
            $application->public_id,
            $session->session_id,
            $fixture['epoch'],
            $fixture['sequence'].'.json.gz',
        ]);
        Storage::disk('local')->put($key, $payload);
        $session->chunks()->create([
            'application_id' => $application->getKey(),
            'epoch_id' => $fixture['epoch'],
            'sequence' => $fixture['sequence'],
            'checksum' => hash('sha256', $payload),
            'compressed_bytes' => strlen($payload),
            'decompressed_bytes' => strlen((string) gzdecode($payload)),
            'event_started_at' => $timestamp,
            'event_ended_at' => $timestamp,
            'object_key' => $key,
        ]);
        $compressedBytes += strlen($payload);
    }

    $session->forceFill(['compressed_bytes' => $compressedBytes])->save();

    return $session;
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

it('streams chunks in epoch and sequence order then atomically publishes before cleanup', function (): void {
    $session = createCompactionFixture([
        ['epoch' => 'epoch-b', 'sequence' => 0, 'label' => 'b-0'],
        ['epoch' => 'epoch-a', 'sequence' => 1, 'label' => 'a-1'],
        ['epoch' => 'epoch-a', 'sequence' => 0, 'label' => 'a-0'],
    ]);
    $temporaryKeys = $session->chunks()->pluck('object_key')->all();

    app(RecordingCompactor::class)->compact($session->getKey());

    $session->refresh();
    $manifest = app(ReplayManifest::class)->read($session->manifest, $session->manifest_checksum);
    $object = $manifest['objects'][0];
    $decoded = gzdecode(Storage::disk('local')->get($object['key']));
    $lines = array_values(array_filter(explode("\n", (string) $decoded)));
    $labels = array_map(
        fn (string $line): string => json_decode($line, true, flags: JSON_THROW_ON_ERROR)[0]['data']['tag'],
        $lines,
    );

    expect($session->status)->toBe(RecordingSessionStatus::Ready)
        ->and($session->transitions()->latest('id')->first()->only(['previous_state', 'new_state', 'reason']))->toBe([
            'previous_state' => 'compacting',
            'new_state' => 'ready',
            'reason' => 'manifest_published',
        ])
        ->and($labels)->toBe(['a-0', 'a-1', 'b-0'])
        ->and($object['checksum'])->toBe(hash('sha256', Storage::disk('local')->get($object['key'])))
        ->and($object['bytes'])->toBe(strlen(Storage::disk('local')->get($object['key'])))
        ->and($session->chunks()->whereNull('purged_at')->count())->toBe(0);

    foreach ($temporaryKeys as $temporaryKey) {
        Storage::disk('local')->assertMissing($temporaryKey);
    }
});

it('reads a valid ordered two object manifest', function (): void {
    $reader = app(ReplayManifest::class);
    $manifest = [
        'manifest_version' => 1,
        'envelope_version' => Envelope::VERSION,
        'rrweb_version' => Envelope::RRWEB_VERSION,
        'compression' => 'gzip',
        'objects' => [
            ['key' => 'first.gz', 'checksum' => str_repeat('a', 64), 'bytes' => 10],
            ['key' => 'second.gz', 'checksum' => str_repeat('b', 64), 'bytes' => 20],
        ],
        'event_started_at' => 100,
        'event_ended_at' => 200,
        'epoch_count' => 2,
        'chunk_count' => 4,
        'gap_count' => 0,
        'incomplete' => false,
        'incomplete_reasons' => [],
        'compaction_state' => 'ready',
    ];

    $read = $reader->read($manifest, $reader->checksum($manifest));

    expect(array_column($read['objects'], 'key'))->toBe(['first.gz', 'second.gz']);
});

it('makes a duplicate compaction job a no-op without creating another object', function (): void {
    $session = createCompactionFixture();
    $compactor = app(RecordingCompactor::class);
    $job = new CompactRecordingSession($session->getKey());
    $job->handle($compactor);
    $firstFiles = Storage::disk('local')->allFiles();

    $job->handle($compactor);

    expect(Storage::disk('local')->allFiles())->toBe($firstFiles)
        ->and($firstFiles)->toHaveCount(1)
        ->and($session->fresh()->compaction_attempts)->toBe(1)
        ->and($session->fresh()->compaction_noop_count)->toBe(1);
});

it('lets deletion win the locked publication race and schedules candidate cleanup', function (): void {
    Queue::fake();
    $session = createCompactionFixture();
    $temporaryKey = $session->chunks()->sole()->object_key;
    $lockQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$lockQueries): void {
        if (str_contains(strtolower($query->sql), 'recording_sessions')
            && str_contains(strtolower($query->sql), 'for update')) {
            $lockQueries[] = $query->sql;
        }
    });
    Event::listen(CompactionCandidateVerified::class, function (CompactionCandidateVerified $event): void {
        RecordingSession::query()->findOrFail($event->recordingSessionId)
            ->transitionTo(RecordingSessionStatus::Deleting, 'concurrent_deletion');
    });

    app(RecordingCompactor::class)->compact($session->getKey());

    $session->refresh();
    expect($session->status)->toBe(RecordingSessionStatus::Deleting)
        ->and($session->manifest)->toBeNull()
        ->and($lockQueries)->not->toBeEmpty()
        ->and(DB::table('operational_counters')->where('metric', 'post_delete_publish_preventions')->value('value'))->toBe(1);
    Storage::disk('local')->assertExists($temporaryKey);
    Queue::assertPushed(CleanupCompactionCandidate::class, 1);
});

it('detects candidate and persisted manifest checksum failures', function (): void {
    $candidateSession = createCompactionFixture();
    Event::listen(CompactionCandidateWritten::class, function (CompactionCandidateWritten $event): void {
        Storage::disk('local')->put($event->candidateKey, 'corrupt-candidate');
    });

    expect(fn () => app(RecordingCompactor::class)->compact($candidateSession->getKey()))
        ->toThrow(RuntimeException::class, 'candidate failed verification');
    expect($candidateSession->fresh()->candidate_checksum_failure_count)->toBe(1)
        ->and(DB::table('operational_counters')->where('metric', 'candidate_checksum_failures')->value('value'))->toBe(1)
        ->and($candidateSession->fresh()->manifest)->toBeNull();

    Event::forget(CompactionCandidateWritten::class);
    $manifestSession = createCompactionFixture();
    app(RecordingCompactor::class)->compact($manifestSession->getKey());
    $manifestSession->refresh();
    $tampered = $manifestSession->manifest;
    $tampered['gap_count']++;

    expect(fn () => app(ReplayManifest::class)->read(
        $tampered,
        $manifestSession->manifest_checksum,
        $manifestSession,
    ))->toThrow(DomainException::class, 'manifest checksum is invalid');
    expect($manifestSession->fresh()->manifest_checksum_failure_count)->toBe(1)
        ->and(DB::table('operational_counters')->where('metric', 'manifest_checksum_failures')->value('value'))->toBe(1);
});

it('never candidate-cleans a published object and resumes interrupted chunk cleanup', function (): void {
    $session = createCompactionFixture();
    $temporaryKey = $session->chunks()->sole()->object_key;
    Event::listen(CompactionPublished::class, function (): void {
        throw new RuntimeException('post-publication cleanup interruption');
    });

    expect(fn () => app(RecordingCompactor::class)->compact($session->getKey()))
        ->toThrow(RuntimeException::class, 'cleanup interruption');

    $session->refresh();
    $publishedKey = $session->manifest['objects'][0]['key'];
    expect($session->status)->toBe(RecordingSessionStatus::Ready);
    Storage::disk('local')->assertExists($publishedKey);
    Storage::disk('local')->assertExists($temporaryKey);

    Event::forget(CompactionPublished::class);
    app(RecordingCompactor::class)->compact($session->getKey());

    Storage::disk('local')->assertExists($publishedKey);
    Storage::disk('local')->assertMissing($temporaryKey);
    expect(Storage::disk('local')->allFiles())->toBe([$publishedKey]);
});

it('finalizes an abandoned gapped session as visibly incomplete', function (): void {
    Queue::fake();
    config()->set('reel_ingest.abandoned_after_seconds', 60);
    config()->set('reel_ingest.late_arrival_window_seconds', 30);
    $session = createCompactionFixture([
        ['epoch' => 'epoch-1', 'sequence' => 0, 'label' => 'first'],
        ['epoch' => 'epoch-1', 'sequence' => 2, 'label' => 'third'],
        ['epoch' => 'privacy-failed', 'sequence' => 0, 'label' => 'validated-prefix'],
    ], RecordingSessionStatus::Recording);
    $session->epochs()->where('epoch_id', 'privacy-failed')->firstOrFail()->forceFill([
        'status' => RecordingEpochStatus::Failed,
        'terminal_sequence' => null,
        'failure_code' => 'privacy_invalid_text_mutation',
    ])->save();
    $session->chunks()->update([
        'event_started_at' => 1_000,
        'event_ended_at' => 2_000,
    ]);
    $session->forceFill(['updated_at' => now()->subMinutes(2)])->saveQuietly();
    $finalizer = app(SessionFinalizer::class);

    expect($finalizer->closeAbandonedSessions())->toBe(1)
        ->and($session->fresh()->status)->toBe(RecordingSessionStatus::Closing);
    expect($finalizer->finalizeClosingSessions())->toBe(0);
    Queue::assertNothingPushed();

    $this->travel(31)->seconds();
    expect($finalizer->finalizeClosingSessions())->toBe(1);
    Queue::assertPushed(CompactRecordingSession::class, 1);

    $session->refresh();
    expect($session->status)->toBe(RecordingSessionStatus::Compacting)
        ->and($session->is_complete)->toBeFalse()
        ->and($session->gap_count)->toBe(1)
        ->and($session->concurrent_epoch_count)->toBe(1)
        ->and($session->incomplete_reasons)->toContain('sequence_gaps:epoch-1')
        ->and($session->incomplete_reasons)->toContain('failed_epoch:privacy-failed')
        ->and($session->incomplete_reasons)->toContain('missing_terminal_sequence:privacy-failed');

    app(RecordingCompactor::class)->compact($session->getKey());
    $session->refresh();
    expect($session->manifest['incomplete'])->toBeTrue()
        ->and($session->manifest['gap_count'])->toBe(1)
        ->and($session->manifest['incomplete_reasons'])->toContain('sequence_gaps:epoch-1')
        ->and($session->manifest['incomplete_reasons'])->toContain('failed_epoch:privacy-failed');
});

it('moves a terminally failed compaction job onto the failed branch', function (): void {
    $session = createCompactionFixture();
    $job = new CompactRecordingSession($session->getKey());

    $job->failed(new RuntimeException('queue attempts exhausted'));

    $session->refresh();
    expect($session->status)->toBe(RecordingSessionStatus::Failed)
        ->and($session->failure_code)->toBe('compaction_failed')
        ->and($session->transitions()->latest('id')->value('reason'))->toBe('compaction_failed');

    $session->transitionTo(RecordingSessionStatus::Deleting, 'delete_failed_session');
    expect($session->status)->toBe(RecordingSessionStatus::Deleting);
});

it('enforces every failure branch in model code and PostgreSQL', function (RecordingSessionStatus $from): void {
    $session = createCompactionFixture(status: $from);
    $session->transitionTo(RecordingSessionStatus::Failed, 'fixture_failure');

    expect($session->status)->toBe(RecordingSessionStatus::Failed)
        ->and(fn () => $session->transitionTo(RecordingSessionStatus::Ready, 'illegal_revival'))
        ->toThrow(DomainException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('recording_sessions')
            ->where('id', $session->getKey())
            ->update(['status' => RecordingSessionStatus::Ready->value])))
        ->toThrow(QueryException::class);

    $session->transitionTo(RecordingSessionStatus::Deleting, 'delete_failed');
    expect($session->status)->toBe(RecordingSessionStatus::Deleting);
})->with([
    'recording' => RecordingSessionStatus::Recording,
    'closing' => RecordingSessionStatus::Closing,
    'compacting' => RecordingSessionStatus::Compacting,
]);

it('returns the operational counters covered by compaction and finalization', function (): void {
    $session = createCompactionFixture();
    $session->forceFill([
        'gap_count' => 2,
        'max_reorder_distance' => 4,
        'conflicting_retry_count' => 3,
        'epoch_count' => 2,
        'concurrent_epoch_count' => 1,
        'is_complete' => false,
        'compaction_attempts' => 2,
        'compaction_duration_ms' => 15,
        'compaction_noop_count' => 1,
        'candidate_checksum_failure_count' => 1,
        'manifest_checksum_failure_count' => 1,
        'status_changed_at' => now()->subHour(),
    ])->save();
    app(OperationalCounters::class)->increment('late_upload_rejections', 2);

    $snapshot = app(OperationalCounters::class)->snapshot(60);

    expect($snapshot)->toMatchArray([
        'gap_count' => 2,
        'maximum_reorder_distance' => 4,
        'incomplete_close_rate' => 1.0,
        'conflicting_retry_count' => 3,
        'concurrent_epoch_count' => 1,
        'sessions_over_state_age_threshold' => 1,
        'late_upload_rejections' => 2,
        'compaction_attempts' => 2,
        'compaction_duration_ms' => 15,
        'compaction_noop_duplicates' => 1,
        'candidate_checksum_failures' => 1,
        'manifest_checksum_failures' => 1,
    ]);
});

it('really queues compaction on the database connection before a worker handles it', function (): void {
    config()->set('queue.default', 'database');
    $session = createCompactionFixture();

    CompactRecordingSession::dispatch($session->getKey());

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($session->fresh()->status)->toBe(RecordingSessionStatus::Compacting)
        ->and($session->fresh()->manifest)->toBeNull();

    $exitCode = Artisan::call('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--sleep' => 0,
    ]);

    expect($exitCode)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($session->fresh()->status)->toBe(RecordingSessionStatus::Ready)
        ->and($session->fresh()->manifest)->not->toBeNull();
});
