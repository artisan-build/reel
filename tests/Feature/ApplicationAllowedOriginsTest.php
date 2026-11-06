<?php

declare(strict_types=1);

use App\Models\Application;
use Illuminate\Support\Str;

it('lets the current owner replace an application complete origin set', function (): void {
    $owner = originSyncOwner();
    $application = Application::factory()->create([
        'allowed_origins' => ['https://detached.example'],
    ]);
    $origins = [
        'https://cloud.example',
        'https://custom.example',
    ];

    $this->withToken($owner['token'])
        ->putJson(route('bfc.applications.allowed-origins.update', $application), [
            'allowed_origins' => $origins,
        ])
        ->assertOk()
        ->assertExactJson([
            'application_id' => $application->public_id,
            'allowed_origins' => $origins,
        ]);

    expect($application->fresh()->allowed_origins)->toBe($origins);
});

it('replays an exact replacement without writing again or mutating another application', function (): void {
    $owner = originSyncOwner();
    $application = Application::factory()->create([
        'allowed_origins' => ['https://old.example'],
    ]);
    $unrelated = Application::factory()->create([
        'allowed_origins' => ['https://unrelated.example'],
    ]);
    $payload = ['allowed_origins' => ['https://current.example']];

    $this->withToken($owner['token'])
        ->putJson(route('bfc.applications.allowed-origins.update', $application), $payload)
        ->assertOk();
    $updatedAt = $application->fresh()->updated_at;
    $this->travel(1)->seconds();

    $this->withToken($owner['token'])
        ->putJson(route('bfc.applications.allowed-origins.update', $application), $payload)
        ->assertOk()
        ->assertExactJson([
            'application_id' => $application->public_id,
            'allowed_origins' => $payload['allowed_origins'],
        ]);

    expect($application->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($unrelated->fresh()->allowed_origins)->toBe(['https://unrelated.example']);
});

it('replaces a detached origin with a new custom domain', function (): void {
    $owner = originSyncOwner();
    $application = Application::factory()->create([
        'allowed_origins' => [
            'https://cloud.example',
            'https://detached.example',
        ],
    ]);
    $replacement = [
        'https://cloud.example',
        'https://new-custom.example',
    ];

    $this->withToken($owner['token'])
        ->putJson(route('bfc.applications.allowed-origins.update', $application), [
            'allowed_origins' => $replacement,
        ])
        ->assertOk();

    expect($application->fresh()->allowed_origins)->toBe($replacement)
        ->not->toContain('https://detached.example');
});

it('rejects missing unknown and revoked credentials without replacing origins', function (string $credential): void {
    $application = Application::factory()->create([
        'allowed_origins' => ['https://current.example'],
    ]);
    $token = 'origin-dead-'.Str::uuid();

    if ($credential === 'revoked') {
        originSyncCredential($token)->forceFill(['revoked_at' => now()])->save();
    }

    $headers = $credential === 'missing'
        ? []
        : ['Authorization' => 'Bearer '.($credential === 'unknown' ? 'unknown-origin-token' : $token)];

    $this->putJson(route('bfc.applications.allowed-origins.update', $application), [
        'allowed_origins' => ['https://attacker.example'],
    ], $headers)->assertUnauthorized();

    expect($application->fresh()->allowed_origins)->toBe(['https://current.example']);
})->with(['missing', 'unknown', 'revoked']);

it('rejects a live credential that does not belong to the current owner', function (): void {
    originSyncOwner();
    $application = Application::factory()->create([
        'allowed_origins' => ['https://current.example'],
    ]);
    $token = 'origin-operator-'.Str::uuid();
    originSyncCredential($token);

    $this->withToken($token)
        ->putJson(route('bfc.applications.allowed-origins.update', $application), [
            'allowed_origins' => ['https://attacker.example'],
        ])
        ->assertForbidden();

    expect($application->fresh()->allowed_origins)->toBe(['https://current.example']);
});

it('returns not found for unknown public ids and does not bind a database id', function (): void {
    $owner = originSyncOwner();
    $application = Application::factory()->create();
    $payload = ['allowed_origins' => ['https://current.example']];
    $unknownId = (string) Str::ulid();

    $this->putJson(route('bfc.applications.allowed-origins.update', ['application' => $unknownId]), $payload)
        ->assertUnauthorized();

    $this->withToken($owner['token'])
        ->putJson(route('bfc.applications.allowed-origins.update', ['application' => $unknownId]), $payload)
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not Found']);
    $this->withToken($owner['token'])
        ->putJson(route('bfc.applications.allowed-origins.update', ['application' => $application->getKey()]), $payload)
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not Found']);

    expect($application->fresh()->allowed_origins)->toBe($application->allowed_origins);
});

it('rejects invalid complete origin sets without changing stored origins', function (array $payload): void {
    $owner = originSyncOwner();
    $application = Application::factory()->create([
        'allowed_origins' => ['https://current.example'],
    ]);

    $this->withToken($owner['token'])
        ->putJson(route('bfc.applications.allowed-origins.update', $application), $payload)
        ->assertUnprocessable();

    expect($application->fresh()->allowed_origins)->toBe(['https://current.example']);
})->with([
    'missing array' => [[]],
    'list root' => [['https://current.example']],
    'empty array' => [['allowed_origins' => []]],
    'associative origin object' => [['allowed_origins' => ['primary' => 'https://current.example']]],
    'sparse origin object' => [['allowed_origins' => [0 => 'https://current.example', 2 => 'https://custom.example']]],
    'oversized array' => [['allowed_origins' => array_map(
        static fn (int $index): string => "https://{$index}.example.test",
        range(1, 21),
    )]],
    'duplicate origin' => [['allowed_origins' => ['https://same.example', 'https://same.example']]],
    'wildcard origin' => [['allowed_origins' => ['https://*.example.com']]],
    'malformed origin' => [['allowed_origins' => ['not-an-origin']]],
    'unparseable origin' => [['allowed_origins' => ['https://[']]],
    'path' => [['allowed_origins' => ['https://example.com/private']]],
    'query' => [['allowed_origins' => ['https://example.com?token=value']]],
    'fragment' => [['allowed_origins' => ['https://example.com#private']]],
    'userinfo' => [['allowed_origins' => ['https://user@example.com']]],
    'wrong scheme' => [['allowed_origins' => ['ftp://example.com']]],
    'non-string member' => [['allowed_origins' => [123]]],
    'extra field' => [['allowed_origins' => ['https://current.example'], 'merge' => true]],
]);
