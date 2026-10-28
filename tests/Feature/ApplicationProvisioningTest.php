<?php

use App\Actions\ProvisionApplication;
use App\Models\Application;
use App\Models\ApplicationProvisioningRequest;
use App\Services\EnrollmentCodeCipher;
use App\Services\ReelCredentialScope;
use ArtisanBuild\BuiltForCloud\AsymmetricVerificationKeys;
use ArtisanBuild\BuiltForCloud\Contracts\AuthorizesCredentialVerbs;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\CredentialVerb;
use ArtisanBuild\BuiltForCloud\OwnerCredentialMinter;
use ArtisanBuild\BuiltForCloud\Ownership;
use ArtisanBuild\BuiltForCloud\Rs256PublicKey;
use ArtisanBuild\BuiltForCloud\Subject;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

function applicationProvisioningCredential(string $token, bool $owner = false): Credential
{
    $credential = resolve(OwnerCredentialMinter::class)->mintFromHash(hash('sha256', $token));

    if ($owner) {
        $ownership = Ownership::current();

        if ($ownership instanceof Ownership) {
            $ownership->forceFill(['owner_credential_id' => $credential->getKey()])->save();
        } else {
            Ownership::query()->create(['owner_credential_id' => $credential->getKey()]);
        }
    }

    return $credential;
}

/** @return array{token: string, credential: Credential} */
function applicationProvisioningOwner(): array
{
    $token = 'owner-'.Str::uuid();

    return [
        'token' => $token,
        'credential' => applicationProvisioningCredential($token, true),
    ];
}

it('atomically provisions an enabled application and returns only its enrollment result', function (): void {
    $owner = applicationProvisioningOwner();
    $requestId = (string) Str::uuid();
    $name = 'Cloud '.Str::random(10);

    $response = $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), [
        'request_id' => $requestId,
        'name' => $name,
    ])->assertCreated();

    $application = Application::query()->where('name', $name)->sole();
    $response->assertExactJson([
        'application_id' => $application->public_id,
        'enrollment_code' => $response->json('enrollment_code'),
        'enrollment_path' => '/bfc/asymmetric-enrollments/'.$application->public_id,
    ])->assertHeader('Cache-Control', 'no-store, private');

    expect($application->ingest_enabled)->toBeTrue()
        ->and($application->allowed_origins)->toBe([])
        ->and(ApplicationProvisioningRequest::query()->whereKey($requestId)->exists())->toBeTrue();
});

it('returns a code that enrolls a key for the provisioned application', function (): void {
    $owner = applicationProvisioningOwner();
    $name = 'Enrollment '.Str::random(10);
    $provisioned = $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), [
        'request_id' => (string) Str::uuid(),
        'name' => $name,
    ])->assertCreated()->json();
    $application = Application::query()->where('name', $name)->sole();
    $keyPair = testRsaKeyPair(true);

    $this->postJson($provisioned['enrollment_path'], [
        'enrollment_code' => $provisioned['enrollment_code'],
        'public_key' => $keyPair['public'],
    ])->assertCreated();

    $keys = resolve(AsymmetricVerificationKeys::class)->for(ReelCredentialScope::for($application));
    expect($provisioned['application_id'])->toBe($application->public_id)
        ->and($keys)->toHaveCount(1)
        ->and($keys[0]->publicKey)->toBe((new Rs256PublicKey($keyPair['public']))->pem);
});

it('replays an exact request without another application or pending credential', function (): void {
    $owner = applicationProvisioningOwner();
    $request = [
        'request_id' => (string) Str::uuid(),
        'name' => 'Retry '.Str::random(10),
    ];

    $first = $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), $request)
        ->assertCreated()->json();
    $second = $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), $request)
        ->assertOk()->json();

    expect($second)->toBe($first)
        ->and(Application::query()->where('name', $request['name'])->count())->toBe(1)
        ->and(Credential::query()
            ->where('kind', CredentialKind::Asymmetric->value)
            ->where('status', CredentialStatus::Pending->value)
            ->where('subject_ref', 'application:'.$first['application_id'])
            ->count())->toBe(1);
});

it('serializes concurrent exact requests without duplicating domain rows', function (): void {
    DB::commit();
    $requestId = (string) Str::uuid();
    $name = 'Concurrent '.Str::random(10);
    $children = [];
    $results = [];

    try {
        for ($index = 0; $index < 2; $index++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            expect($pair)->not->toBeFalse();
            $pid = pcntl_fork();
            expect($pid)->not->toBe(-1);

            if ($pid === 0) {
                fclose($pair[0]);
                fread($pair[1], 1);
                DB::purge();

                try {
                    $result = resolve(ProvisionApplication::class)($requestId, $name, 'concurrent-owner');
                    fwrite($pair[1], json_encode($result?->response(), JSON_THROW_ON_ERROR));
                } catch (Throwable $exception) {
                    fwrite($pair[1], json_encode(['error' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
                }

                fclose($pair[1]);
                exit(0);
            }

            fclose($pair[1]);
            $children[] = ['pid' => $pid, 'socket' => $pair[0]];
        }

        foreach ($children as $child) {
            fwrite($child['socket'], '1');
        }

        foreach ($children as $child) {
            $results[] = json_decode(stream_get_contents($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        DB::purge();
        DB::reconnect();
        $application = Application::query()->where('name', $name)->sole();

        expect($results[0])->toBe($results[1])
            ->and($results[0])->not->toHaveKey('error')
            ->and(Application::query()->where('name', $name)->count())->toBe(1)
            ->and(ApplicationProvisioningRequest::query()->whereKey($requestId)->count())->toBe(1)
            ->and(Credential::query()->where('subject_ref', 'application:'.$application->public_id)->count())->toBe(1);
    } finally {
        DB::purge();
        DB::reconnect();
        $application = Application::query()->where('name', $name)->first();

        if ($application instanceof Application) {
            $credentialIds = Credential::query()
                ->where('subject_ref', 'application:'.$application->public_id)
                ->pluck('id');
            DB::table('onboarding_tokens')->whereIn('durable_credential_id', $credentialIds)->delete();

            try {
                DB::statement('ALTER TABLE credential_audit_events DISABLE TRIGGER credential_audit_events_append_only');
                DB::table('credential_audit_events')->whereIn('credential_id', $credentialIds)->delete();
            } finally {
                DB::statement('ALTER TABLE credential_audit_events ENABLE TRIGGER credential_audit_events_append_only');
            }

            Credential::query()->whereKey($credentialIds)->delete();
            $application->delete();
        }
    }
})->skip(! function_exists('pcntl_fork'), 'pcntl is required for the concurrency test.');

it('conflicts when a request id is reused with a different name', function (): void {
    $owner = applicationProvisioningOwner();
    $requestId = (string) Str::uuid();
    $firstName = 'First '.Str::random(10);
    $secondName = 'Second '.Str::random(10);

    $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), [
        'request_id' => $requestId,
        'name' => $firstName,
    ])->assertCreated();

    $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), [
        'request_id' => $requestId,
        'name' => $secondName,
    ])->assertConflict();

    expect(Application::query()->whereIn('name', [$firstName, $secondName])->pluck('name')->all())
        ->toBe([$firstName]);
});

it('rejects missing unknown and dead credentials without creating an application', function (string $credential): void {
    $token = 'dead-'.Str::uuid();

    if ($credential === 'dead') {
        applicationProvisioningCredential($token)->forceFill(['revoked_at' => now()])->save();
    }

    $request = $this->postJson(route('bfc.applications.store'), [
        'request_id' => (string) Str::uuid(),
        'name' => 'Unauthorized '.Str::random(10),
    ], $credential === 'missing' ? [] : ['Authorization' => 'Bearer '.($credential === 'unknown' ? 'unknown-token' : $token)]);

    $request->assertUnauthorized();
    expect(Application::query()->where('name', 'like', 'Unauthorized %')->exists())->toBeFalse();
})->with(['missing', 'unknown', 'dead']);

it('rejects a live operator credential that is not the current owner credential', function (): void {
    applicationProvisioningOwner();
    $token = 'operator-'.Str::uuid();
    applicationProvisioningCredential($token);
    $name = 'Non-owner '.Str::random(10);

    $this->withToken($token)->postJson(route('bfc.applications.store'), [
        'request_id' => (string) Str::uuid(),
        'name' => $name,
    ])->assertForbidden();

    expect(Application::query()->where('name', $name)->exists())->toBeFalse();
});

it('translates a declaration issue refusal to forbidden and rolls back provisioning', function (): void {
    $owner = applicationProvisioningOwner();
    $name = 'Refused '.Str::random(10);
    app()->instance(CredentialDeclaration::class, new class implements AuthorizesCredentialVerbs, CredentialDeclaration
    {
        public function resolveSubject(Request $request): ?Subject
        {
            return null;
        }

        public function authorize(Credential $credential, ?string $ability, Request $request): bool
        {
            return true;
        }

        public function authorizeVerb(CredentialVerb $verb, ?Subject $subject, Request $request): bool
        {
            return $verb !== CredentialVerb::Issue;
        }
    });

    $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), [
        'request_id' => (string) Str::uuid(),
        'name' => $name,
    ])->assertForbidden();

    expect(Application::query()->where('name', $name)->exists())->toBeFalse()
        ->and(ApplicationProvisioningRequest::query()->where('name', $name)->exists())->toBeFalse()
        ->and(Credential::query()->where('subject_ref', 'like', 'application:%')->exists())->toBeFalse();
});

it('validates the request before provisioning', function (): void {
    $owner = applicationProvisioningOwner();

    $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), [
        'request_id' => 'not-a-uuid',
        'name' => '',
    ])->assertUnprocessable()->assertJsonValidationErrors(['request_id', 'name']);

    expect(Application::query()->exists())->toBeFalse();
});

it('rolls back the application and ledger when credential minting fails', function (): void {
    $owner = applicationProvisioningOwner();
    $name = 'Mint failure '.Str::random(10);
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION reject_reel_provisioning_credential() RETURNS trigger AS $$
        BEGIN
            IF NEW.kind = 'asymmetric' AND NEW.subject_ref LIKE 'application:%' THEN
                RAISE EXCEPTION 'forced mint failure';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
    SQL);
    DB::unprepared('CREATE TRIGGER reject_reel_provisioning_credential BEFORE INSERT ON credentials FOR EACH ROW EXECUTE FUNCTION reject_reel_provisioning_credential()');

    $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), [
        'request_id' => (string) Str::uuid(),
        'name' => $name,
    ])->assertInternalServerError();

    expect(Application::query()->where('name', $name)->exists())->toBeFalse()
        ->and(ApplicationProvisioningRequest::query()->where('name', $name)->exists())->toBeFalse()
        ->and(Credential::query()->where('subject_ref', 'like', 'application:%')->exists())->toBeFalse();
});

it('keeps enrollment plaintext out of persisted rows logs and exception traces', function (): void {
    $owner = applicationProvisioningOwner();
    $messages = [];
    Log::listen(function (MessageLogged $event) use (&$messages): void {
        $messages[] = $event->message.' '.json_encode($event->context, JSON_THROW_ON_ERROR);
    });
    $response = $this->withToken($owner['token'])->postJson(route('bfc.applications.store'), [
        'request_id' => (string) Str::uuid(),
        'name' => 'Custody '.Str::random(10),
    ])->assertCreated();
    $code = $response->json('enrollment_code');
    $persisted = collect([
        'application_provisioning_requests',
        'applications',
        'credentials',
        'credential_protocol_bindings',
        'onboarding_tokens',
        'credential_audit_events',
    ])->map(fn (string $table): string => DB::table($table)->get()->toJson())->implode("\n");
    $logged = implode("\n", $messages);

    expect($persisted)->not->toContain($code);
    expect($logged)->not->toContain($code);

    $encrypter = new class implements Encrypter
    {
        public function encrypt(#[SensitiveParameter] $value, $serialize = true): never
        {
            throw new RuntimeException('Encryption failed safely.');
        }

        public function decrypt($payload, $unserialize = true): never
        {
            throw new RuntimeException('Not used.');
        }

        public function getKey(): string
        {
            return '';
        }

        public function getAllKeys(): array
        {
            return [];
        }

        public function getPreviousKeys(): array
        {
            return [];
        }
    };
    $cipher = new EnrollmentCodeCipher($encrypter);

    foreach (['r3elC0de', '-----BEGIN PRIVATE KEY-----'] as $needle) {
        try {
            $cipher->encrypt($needle);
        } catch (RuntimeException $exception) {
            expect($exception->getMessage()."\n".$exception->getTraceAsString())->not->toContain($needle);
        }
    }
});
