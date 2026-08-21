<?php

declare(strict_types=1);

use App\Enums\RecordingSessionStatus;
use App\Exceptions\RetentionRejected;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Models\RecordingSession;
use App\Models\User;
use App\Models\UserErasureAudit;
use App\Services\OperationalCounters;
use App\Services\OrphanSweeper;
use App\Services\RecordingDeletion;
use App\Services\RecordingProtection;
use App\Services\ReplayManifest;
use App\Services\RetentionDiagnostics;
use App\Services\SessionFinalizer;
use ArtisanBuild\ReelClient\Envelope;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** @param array<string, mixed> $attributes */
function makeRetentionSession(array $attributes = []): RecordingSession
{
    $application = $attributes['application'] ?? Application::factory()->create();
    $credential = ApplicationCredential::factory()->for($application)->create();
    $status = $attributes['status'] ?? RecordingSessionStatus::Ready;
    unset($attributes['application'], $attributes['status']);
    $sessionId = $attributes['session_id'] ?? bin2hex(random_bytes(32));
    $objectKey = "reel/chunks/{$application->public_id}/{$sessionId}/published/replay.jsonl.gz";
    $bytes = gzencode("[]\n");

    if ($bytes === false) {
        throw new RuntimeException('Unable to create retention fixture.');
    }

    Storage::disk('local')->put($objectKey, $bytes);
    $manifest = [
        'manifest_version' => 1,
        'envelope_version' => Envelope::VERSION,
        'rrweb_version' => Envelope::RRWEB_VERSION,
        'compression' => Envelope::COMPRESSION,
        'objects' => [[
            'key' => $objectKey,
            'checksum' => hash('sha256', $bytes),
            'bytes' => strlen($bytes),
        ]],
        'event_started_at' => 1_000,
        'event_ended_at' => 1_000,
        'epoch_count' => 0,
        'chunk_count' => 0,
        'gap_count' => 0,
        'incomplete' => true,
        'incomplete_reasons' => ['missing_epoch'],
        'compaction_state' => 'ready',
    ];
    $session = new RecordingSession;
    $values = array_merge([
        'application_id' => $application->getKey(),
        'application_credential_id' => $credential->getKey(),
        'session_id' => $sessionId,
        'grant_id_hash' => hash('sha256', $sessionId),
        'origin' => 'https://monitored.example',
        'protocol_version' => Envelope::VERSION,
        'max_chunks' => 10,
        'max_compressed_bytes' => 1_000_000,
        'max_chunk_bytes' => 100_000,
        'compressed_bytes' => strlen($bytes),
        'started_at' => now()->subDays(31),
        'max_event_time' => now()->subDays(31)->addMinutes(30),
        'upload_cutoff_at' => now()->subDays(31)->addMinutes(31),
        'ended_at' => now()->subDays(30),
        'maximum_expires_at' => now()->subMinute(),
        'expires_at' => now(),
        'delete_not_before' => now(),
        'status_changed_at' => now(),
        'is_complete' => true,
        'incomplete_reasons' => [],
        'manifest' => $manifest,
        'manifest_checksum' => resolve(ReplayManifest::class)->checksum($manifest),
        'compacted_at' => now(),
    ], $attributes);
    $session->fill($values);
    $session->forceFill([...$values, 'status' => $status])->save();

    return $session->fresh(['application']);
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

it('sets ended expiry and initial deletion deadline to exactly thirty days', function (): void {
    Queue::fake();
    $session = makeRetentionSession([
        'status' => RecordingSessionStatus::Closing,
        'closing_cutoff_at' => now()->subSecond(),
        'ended_at' => null,
        'expires_at' => null,
        'delete_not_before' => null,
    ]);

    expect(resolve(SessionFinalizer::class)->finalizeClosingSessions())->toBe(1);

    $session->refresh();
    expect($session->ended_at)->not->toBeNull()
        ->and($session->expires_at->getTimestamp() - $session->ended_at->getTimestamp())->toBe(30 * 24 * 60 * 60)
        ->and($session->delete_not_before->getTimestamp())->toBe($session->expires_at->getTimestamp());
});

it('deletes only overdue unprotected sessions while protected sessions survive the same sweep', function (): void {
    $owner = User::factory()->create();
    $ordinary = makeRetentionSession(['delete_not_before' => now()->subSecond()]);
    $protected = makeRetentionSession([
        'delete_not_before' => now()->subSecond(),
        'protected_at' => now()->subDay(),
        'protected_by' => $owner->getKey(),
    ]);
    $ordinaryObject = $ordinary->manifest['objects'][0]['key'];
    $protectedObject = $protected->manifest['objects'][0]['key'];

    expect(Artisan::call('reel:retain-sessions'))->toBe(0);

    expect($ordinary->fresh()->status)->toBe(RecordingSessionStatus::Deleted)
        ->and($ordinary->fresh()->manifest)->toBeNull()
        ->and($protected->fresh()->status)->toBe(RecordingSessionStatus::Ready)
        ->and($protected->fresh()->protected_by)->toBe($owner->getKey());
    Storage::disk('local')->assertMissing($ordinaryObject)->assertExists($protectedObject);
});

it('protects only ready sessions and cannot replace the first protection owner', function (): void {
    $firstOwner = User::factory()->create();
    $otherUser = User::factory()->create();
    $ready = makeRetentionSession();
    $failed = makeRetentionSession(['status' => RecordingSessionStatus::Failed]);
    $protection = resolve(RecordingProtection::class);

    expect($protection->protect($ready->getKey(), $firstOwner))->toBeTrue()
        ->and($protection->protect($ready->getKey(), $otherUser))->toBeFalse();
    expect($ready->fresh()->protected_by)->toBe($firstOwner->getKey())
        ->and($ready->protectionEvents()->where('action', 'protected')->count())->toBe(1);

    try {
        $protection->protect($failed->getKey(), $firstOwner);
        $this->fail('A failed session was protected.');
    } catch (RetentionRejected $rejection) {
        expect($rejection->reason)->toBe('session_not_protectable')
            ->and($rejection->httpStatus)->toBe(409);
    }
    expect($failed->fresh()->protected_at)->toBeNull()
        ->and($failed->protectionEvents()->count())->toBe(0);
});

it('rejects a genuinely different ordinary user from unprotecting and preserves persisted protection', function (): void {
    $owner = User::factory()->create();
    $administrator = User::factory()->admin()->create();
    $thirdParty = User::factory()->create();
    $session = makeRetentionSession([
        'protected_at' => now()->subHour(),
        'protected_by' => $owner->getKey(),
    ]);
    $originalDeadline = $session->delete_not_before;

    $this->actingAs($thirdParty)->delete(route('sessions.protection.destroy', [
        'application' => $session->application,
        'recordingSession' => $session,
    ]))->assertForbidden()->assertSee('protection_owned_by_another_user');

    $session->refresh();
    expect($session->protected_by)->toBe($owner->getKey())
        ->and($session->protected_at)->not->toBeNull()
        ->and($session->delete_not_before->getTimestamp())->toBe($originalDeadline->getTimestamp())
        ->and($session->protectionEvents()->count())->toBe(0)
        ->and($administrator->getKey())->not->toBe($owner->getKey())
        ->and($thirdParty->getKey())->not->toBe($owner->getKey());
});

it('allows only an administrator to unprotect when the protection owner has been deleted', function (): void {
    $owner = User::factory()->create();
    $administrator = User::factory()->admin()->create();
    $thirdParty = User::factory()->create();
    $session = makeRetentionSession();
    resolve(RecordingProtection::class)->protect($session->getKey(), $owner);
    $owner->delete();

    expect($session->fresh()->protected_by)->toBeNull()
        ->and($session->fresh()->protected_at)->not->toBeNull();
    expect(fn () => resolve(RecordingProtection::class)->unprotect($session->getKey(), $thirdParty))
        ->toThrow(RetentionRejected::class, 'protection_owned_by_another_user');
    expect($session->fresh()->protected_at)->not->toBeNull();

    expect(resolve(RecordingProtection::class)->unprotect($session->getKey(), $administrator))->toBeTrue()
        ->and($session->fresh()->protected_at)->toBeNull();
});

it('applies the later cooling deadline advertises its actor and allows another user to re-protect', function (): void {
    $owner = User::factory()->create();
    $newOwner = User::factory()->create();
    $session = makeRetentionSession([
        'expires_at' => now()->addHour(),
        'delete_not_before' => now()->addHour(),
    ]);
    $protection = resolve(RecordingProtection::class);
    $protection->protect($session->getKey(), $owner);
    $unprotectedAt = now();
    $protection->unprotect($session->getKey(), $owner);
    $session->refresh();

    expect($session->delete_not_before->getTimestamp())->toBe($unprotectedAt->addHours(72)->getTimestamp());
    $this->actingAs($owner)->get(route('sessions.show', [
        'application' => $session->application,
        'recordingSession' => $session,
    ]))->assertOk()
        ->assertSee("Unprotected by {$owner->name}")
        ->assertSee($session->delete_not_before->toDayDateTimeString());

    expect($protection->protect($session->getKey(), $newOwner))->toBeTrue()
        ->and($session->fresh()->protected_by)->toBe($newOwner->getKey())
        ->and($session->fresh()->protected_at)->not->toBeNull();
});

it('prevents ordinary deletion while an administrator immediately overrides protection and cooling', function (): void {
    $owner = User::factory()->create();
    $administrator = User::factory()->admin()->create();
    $session = makeRetentionSession([
        'protected_at' => now(),
        'protected_by' => $owner->getKey(),
        'delete_not_before' => now()->addDays(10),
    ]);
    $object = $session->manifest['objects'][0]['key'];
    $route = route('admin.sessions.destroy', [
        'application' => $session->application,
        'recordingSession' => $session,
    ]);

    $this->actingAs($owner)->delete($route)->assertForbidden();
    expect($session->fresh()->status)->toBe(RecordingSessionStatus::Ready)
        ->and($session->fresh()->protected_by)->toBe($owner->getKey());
    Storage::disk('local')->assertExists($object);

    $this->actingAs($administrator)->delete($route)->assertRedirect(route('sessions.index'));
    expect($session->fresh()->status)->toBe(RecordingSessionStatus::Deleted)
        ->and($session->fresh()->deletion_actor_id)->toBe($administrator->getKey())
        ->and($session->fresh()->deletion_reason)->toBe('administrator_deleted');
    Storage::disk('local')->assertMissing($object);
});

it('requires exact erasure confirmation and audits a batch without the erased user id', function (): void {
    $administrator = User::factory()->admin()->create();
    $application = Application::factory()->create();
    $erasedId = 'customer-sensitive-42';
    $protected = makeRetentionSession([
        'application' => $application,
        'application_user_id' => $erasedId,
        'protected_at' => now(),
        'protected_by' => User::factory()->create()->getKey(),
    ]);
    $ordinary = makeRetentionSession(['application' => $application, 'application_user_id' => $erasedId]);
    $unrelated = makeRetentionSession(['application' => $application, 'application_user_id' => 'customer-elsewhere']);
    $route = route('admin.application-users.destroy', ['application' => $application]);

    $this->actingAs($administrator)->post($route, [
        'application_user_id' => $erasedId,
        'confirmation' => 'wrong-user',
    ])->assertUnprocessable()->assertSee('erasure_confirmation_required');
    expect($protected->fresh()->status)->toBe(RecordingSessionStatus::Ready)
        ->and(UserErasureAudit::query()->count())->toBe(0);

    $this->post($route, [
        'application_user_id' => $erasedId,
        'confirmation' => $erasedId,
    ])->assertRedirect();

    $audit = UserErasureAudit::query()->sole();
    expect($audit->actor_user_id)->toBe($administrator->getKey())
        ->and($audit->application_id)->toBe($application->getKey())
        ->and($audit->matched_count)->toBe(2)
        ->and($audit->deleted_count)->toBe(2)
        ->and($audit->failed_count)->toBe(0)
        ->and($audit->outcome)->toBe('completed')
        ->and($audit->batch_id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and(json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($erasedId)
        ->and(DB::getSchemaBuilder()->getColumnListing('user_erasure_audits'))->not->toContain('application_user_id');
    expect($protected->fresh()->status)->toBe(RecordingSessionStatus::Deleted)
        ->and($protected->fresh()->application_user_id)->toBeNull()
        ->and($ordinary->fresh()->status)->toBe(RecordingSessionStatus::Deleted)
        ->and($unrelated->fresh()->status)->toBe(RecordingSessionStatus::Ready)
        ->and($unrelated->fresh()->application_user_id)->toBe('customer-elsewhere');
});

it('makes deletion terminal for protection and replay delivery with specific persisted outcomes', function (): void {
    $viewer = User::factory()->create();
    $session = makeRetentionSession();
    $signedUrl = URL::temporarySignedRoute('sessions.player', now()->addMinute(), [
        'application' => $session->application,
        'recordingSession' => $session,
        'channel' => str_repeat('a', 96),
    ]);
    $session->transitionTo(RecordingSessionStatus::Deleting, 'test_deletion_started');

    expect(fn () => resolve(RecordingProtection::class)->protect($session->getKey(), $viewer))
        ->toThrow(RetentionRejected::class, 'session_not_protectable');
    expect($session->fresh()->status)->toBe(RecordingSessionStatus::Deleting)
        ->and($session->fresh()->protected_at)->toBeNull()
        ->and($session->protectionEvents()->count())->toBe(0);

    $this->actingAs($viewer)->getJson(route('sessions.player-url', [
        'application' => $session->application,
        'recordingSession' => $session,
        'channel' => str_repeat('a', 96),
    ]))->assertConflict()->assertSee('replay_deletion_started');
    $this->get($signedUrl)->assertOk()->assertSee('replay_deletion_started');
    expect($session->replayViews()->count())->toBe(0);
});

it('removes temporary candidate and published objects only under the exact session prefix', function (): void {
    $administrator = User::factory()->admin()->create();
    $session = makeRetentionSession();
    $prefix = resolve(RecordingDeletion::class)->prefix($session);
    $temporary = "{$prefix}/epoch-1/0.json.gz";
    $candidate = "{$prefix}/candidates/candidate.jsonl.gz";
    $outside = "{$prefix}0/candidates/not-this-session.jsonl.gz";
    Storage::disk('local')->put($temporary, 'temporary');
    Storage::disk('local')->put($candidate, 'candidate');
    Storage::disk('local')->put($outside, 'outside');

    expect(resolve(RecordingDeletion::class)->delete($session->getKey(), 'test_exact_prefix', $administrator))->toBeTrue()
        ->and(resolve(RecordingDeletion::class)->delete($session->getKey(), 'test_idempotent_retry', $administrator))->toBeTrue();
    expect($session->fresh()->status)->toBe(RecordingSessionStatus::Deleted)
        ->and($session->fresh()->manifest)->toBeNull()
        ->and($session->fresh()->chunks()->count())->toBe(0);
    Storage::disk('local')->assertMissing($temporary)->assertMissing($candidate)->assertExists($outside);
});

it('keeps object-store deletion failure visibly incomplete and retryable', function (): void {
    $session = makeRetentionSession();
    $object = $session->manifest['objects'][0]['key'];
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('allFiles')->twice()->andReturn([$object]);
    $disk->shouldReceive('delete')->once()->with($object)->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    expect(resolve(RecordingDeletion::class)->delete($session->getKey(), 'test_storage_failure'))->toBeFalse();

    $session->refresh();
    expect($session->status)->toBe(RecordingSessionStatus::Deleting)
        ->and($session->deletion_completed_at)->toBeNull()
        ->and($session->deletion_attempts)->toBe(1)
        ->and($session->deletion_last_error)->toBe('objects_remain')
        ->and($session->deletion_remaining_objects)->toBe(1)
        ->and($session->manifest)->not->toBeNull();
});

it('keeps the deletion repair command dry by default and applies only with an explicit flag', function (): void {
    $session = makeRetentionSession([
        'status' => RecordingSessionStatus::Deleting,
        'deletion_started_at' => now()->subMinute(),
        'deletion_attempts' => 1,
        'deletion_last_error' => 'objects_remain',
        'deletion_remaining_objects' => 1,
    ]);
    $object = $session->manifest['objects'][0]['key'];
    $before = $session->getAttributes();

    expect(Artisan::call('reel:retry-deletions'))->toBe(0)
        ->and($session->fresh()->getAttributes())->toBe($before);
    Storage::disk('local')->assertExists($object);

    expect(Artisan::call('reel:retry-deletions', ['--apply' => true]))->toBe(0)
        ->and($session->fresh()->status)->toBe(RecordingSessionStatus::Deleted)
        ->and($session->fresh()->deletion_attempts)->toBe(2);
    Storage::disk('local')->assertMissing($object);
});

it('deletes only old unreferenced objects and suspension blocks destructive orphan work', function (): void {
    config()->set('reel_retention.orphan_safety_delay_hours', 1);
    $session = makeRetentionSession();
    $live = $session->manifest['objects'][0]['key'];
    $oldOrphan = 'reel/chunks/orphan-app/orphan-session/old.gz';
    $recentOrphan = 'reel/chunks/orphan-app/orphan-session/recent.gz';
    Storage::disk('local')->put($oldOrphan, 'old');
    Storage::disk('local')->put($recentOrphan, 'recent');
    touch(Storage::disk('local')->path($oldOrphan), now()->subHours(2)->getTimestamp());

    $result = resolve(OrphanSweeper::class)->sweep();
    expect($result)->toMatchArray([
        'suspended' => false,
        'orphan_count' => 2,
        'eligible_count' => 1,
        'deleted_count' => 1,
    ]);
    Storage::disk('local')->assertExists($live)->assertMissing($oldOrphan)->assertExists($recentOrphan);

    $blocked = 'reel/chunks/orphan-app/orphan-session/blocked.gz';
    Storage::disk('local')->put($blocked, 'blocked');
    touch(Storage::disk('local')->path($blocked), now()->subHours(2)->getTimestamp());
    resolve(OrphanSweeper::class)->suspend('restore_uncertainty');

    expect(resolve(OrphanSweeper::class)->sweep()['suspended'])->toBeTrue();
    Storage::disk('local')->assertExists($blocked);
    expect(DB::table('retention_states')->where('id', 1)->value('orphan_sweeper_suspended'))->toBeTrue();
});

it('keeps storage reconciliation dry by default then explicitly records high waters and resumes', function (): void {
    resolve(OrphanSweeper::class)->suspend('restore_uncertainty');
    Storage::disk('local')->put('reel/chunks/orphan/app/file.gz', 'orphan');
    $before = (array) DB::table('retention_states')->where('id', 1)->first();
    $filesBefore = Storage::disk('local')->allFiles('reel/chunks');

    expect(Artisan::call('reel:reconcile-storage'))->toBe(0);

    expect((array) DB::table('retention_states')->where('id', 1)->first())->toBe($before)
        ->and(Storage::disk('local')->allFiles('reel/chunks'))->toBe($filesBefore);

    expect(Artisan::call('reel:reconcile-storage', ['--apply' => true]))->toBe(0);
    $after = DB::table('retention_states')->where('id', 1)->first();
    expect($after->orphan_sweeper_suspended)->toBeFalse()
        ->and($after->reconciled_at)->not->toBeNull()
        ->and($after->object_high_water_at)->not->toBeNull();
    Storage::disk('local')->assertExists('reel/chunks/orphan/app/file.gz');
});

it('surfaces retention convergence protection and storage diagnostics', function (): void {
    $owner = User::factory()->create();
    makeRetentionSession([
        'protected_at' => now(),
        'protected_by' => $owner->getKey(),
        'compressed_bytes' => 1234,
    ]);
    $deleting = makeRetentionSession([
        'status' => RecordingSessionStatus::Deleting,
        'deletion_started_at' => now()->subMinutes(5),
        'deletion_attempts' => 3,
        'deletion_remaining_objects' => 2,
        'compressed_bytes' => 4321,
    ]);
    resolve(OperationalCounters::class)->increment('post_delete_publish_preventions', 2);

    $snapshot = resolve(RetentionDiagnostics::class)->snapshot();
    expect($snapshot)->toMatchArray([
        'protected_count' => 1,
        'estimated_storage_bytes' => 5555,
        'deleting_count' => 1,
        'deletion_retries' => 3,
        'deletion_remaining_prefix_objects' => 2,
        'post_delete_publish_preventions' => 2,
        'orphan_sweeper_suspended' => false,
    ])->and($snapshot['oldest_deleting_age_seconds'])->toBeGreaterThanOrEqual(300);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Protected recordings')
        ->assertSee('Remaining prefix objects')
        ->assertSee((string) $deleting->deletion_remaining_objects);
});

it('documents that unconditional bucket lifecycle deletion is forbidden', function (): void {
    $documentation = file_get_contents(base_path('docs/retention.md'));

    expect($documentation)->toBeString()
        ->toContain('Do not configure an unconditional')
        ->toContain('bucket lifecycle rule')
        ->toContain('protected recordings');
    expect(config('filesystems.disks.local'))->not->toHaveKey('lifecycle')
        ->and(config('filesystems.disks.s3'))->not->toHaveKey('lifecycle');
});

it('lets deleting win a real PostgreSQL row-lock race against protection', function (): void {
    $raceConnection = 'retention_race';
    config()->set("database.connections.{$raceConnection}", config('database.connections.pgsql'));
    $actor = User::factory()->make();
    $actor->setConnection($raceConnection);
    $actor->save();
    $application = Application::factory()->make();
    $application->setConnection($raceConnection);
    $application->save();
    $credential = new ApplicationCredential;
    $credential->setConnection($raceConnection);
    $credential->forceFill([
        'application_id' => $application->getKey(),
        'algorithm' => ApplicationCredential::ALGORITHM,
        'enrollment_code_hash' => hash('sha256', 'race'),
        'enrollment_expires_at' => now()->addMinute(),
    ])->save();
    $session = new RecordingSession;
    $session->setConnection($raceConnection);
    $session->fill([
        'application_id' => $application->getKey(),
        'application_credential_id' => $credential->getKey(),
        'session_id' => bin2hex(random_bytes(32)),
        'grant_id_hash' => hash('sha256', 'race'),
        'origin' => 'https://race.example',
        'protocol_version' => Envelope::VERSION,
        'max_chunks' => 1,
        'max_compressed_bytes' => 100,
        'max_chunk_bytes' => 100,
        'started_at' => now()->subMinute(),
        'max_event_time' => now(),
        'upload_cutoff_at' => now(),
        'maximum_expires_at' => now()->addDays(30),
        'expires_at' => now()->addDays(30),
        'delete_not_before' => now()->addDays(30),
        'status_changed_at' => now(),
    ]);
    $session->forceFill(['status' => RecordingSessionStatus::Ready])->save();
    $connection = DB::connection($raceConnection);
    $connection->beginTransaction();
    $locked = RecordingSession::on($raceConnection)->lockForUpdate()->findOrFail($session->getKey());
    $locked->forceFill([
        'status' => RecordingSessionStatus::Deleting,
        'status_changed_at' => now(),
        'deletion_started_at' => now(),
        'deletion_reason' => 'race_deletion',
    ])->save();
    $resultPath = sys_get_temp_dir().'/retention-race-result-'.Str::uuid().'.json';
    $scriptPath = sys_get_temp_dir().'/retention-race-worker-'.Str::uuid().'.php';
    file_put_contents($scriptPath, <<<'PHP'
<?php

require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    resolve(App\Services\RecordingProtection::class)->protect(
        (int) $argv[2],
        App\Models\User::query()->findOrFail((int) $argv[3]),
    );
    $result = ['protected' => true, 'reason' => null];
} catch (App\Exceptions\RetentionRejected $rejection) {
    $result = ['protected' => false, 'reason' => $rejection->reason];
}

file_put_contents($argv[4], json_encode($result, JSON_THROW_ON_ERROR));
PHP);
    $database = config('database.connections.pgsql');
    $process = new Process([
        PHP_BINARY,
        $scriptPath,
        base_path(),
        (string) $session->getKey(),
        (string) $actor->getKey(),
        $resultPath,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => (string) $database['database'],
        'DB_USERNAME' => (string) $database['username'],
        'DB_PASSWORD' => (string) $database['password'],
        'PGAPPNAME' => 'reel-retention-race',
    ]);
    $process->start();
    usleep(500_000);
    $waitType = $connection->table('pg_stat_activity')
        ->where('application_name', 'reel-retention-race')
        ->value('wait_event_type');
    expect($process->isRunning())->toBeTrue('Protection process exited before the deletion lock was released.')
        ->and($waitType)->toBe('Lock');
    $connection->commit();
    $process->wait();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
    @unlink($resultPath);
    @unlink($scriptPath);

    $persisted = RecordingSession::on($raceConnection)->findOrFail($session->getKey());
    expect($result)->toBe(['protected' => false, 'reason' => 'session_not_protectable'])
        ->and($persisted->status)->toBe(RecordingSessionStatus::Deleting)
        ->and($persisted->protected_at)->toBeNull()
        ->and($persisted->protected_by)->toBeNull()
        ->and($persisted->protectionEvents()->count())->toBe(0);

    DB::connection($raceConnection)->table('applications')->where('id', $application->getKey())->delete();
    DB::connection($raceConnection)->table('users')->where('id', $actor->getKey())->delete();
    DB::purge($raceConnection);
});
