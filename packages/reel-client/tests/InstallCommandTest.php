<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\KeyMaterial;
use ArtisanBuild\ReelClient\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(TestCase::class);

it('generates the private key locally and enrolls only the public key', function (): void {
    $directory = sys_get_temp_dir().'/reel-install-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    $environment = "APP_NAME=Fixture\r\n# untouched comment\r\nCUSTOM_VALUE='byte exact'\r\nREEL_URL=\"https://old.example\"\r\n";
    file_put_contents($directory.'/.env', $environment);
    $this->app->useEnvironmentPath($directory);

    Http::fake([
        'https://reel.example/api/applications/app-public-id/enrollment' => Http::response([
            'credential_id' => 42,
            'application_id' => 'app-public-id',
            'algorithm' => 'RS256',
        ], 201),
    ]);

    $this->artisan('reel:install', [
        '--url' => 'https://reel.example/',
        '--application' => 'app-public-id',
        '--enrollment-code' => 'one-use-code',
    ])->expectsOutputToContain('The signing key remains local')->assertSuccessful();

    Http::assertSent(function (Request $request): bool {
        $publicKey = $request->data()['public_key'] ?? '';

        expect($request->data())->toHaveKeys(['enrollment_code', 'algorithm', 'public_key'])
            ->not->toHaveKey('private_key')
            ->and($request['enrollment_code'])->toBe('one-use-code')
            ->and($request['algorithm'])->toBe('RS256')
            ->and($publicKey)->toContain('BEGIN PUBLIC KEY')
            ->not->toContain('PRIVATE');

        return true;
    });

    $written = file_get_contents($directory.'/.env');
    expect($written)->toContain("APP_NAME=Fixture\r\n# untouched comment\r\nCUSTOM_VALUE='byte exact'\r\n")
        ->and($written)->toContain('REEL_URL="https://reel.example"')
        ->and($written)->toContain('REEL_APPLICATION_ID="app-public-id"')
        ->and($written)->not->toContain('BEGIN PRIVATE KEY');

    preg_match('/^REEL_PRIVATE_KEY="([^"]+)"\r?$/m', $written, $matches);
    expect($matches)->toHaveCount(2)
        ->and(KeyMaterial::decodePrivateKey($matches[1]))->toContain('BEGIN PRIVATE KEY');

    unlink($directory.'/.env');
    rmdir($directory);
});

it('does not install a private key when enrollment fails', function (): void {
    $directory = sys_get_temp_dir().'/reel-install-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/.env', "UNCHANGED=yes\n");
    $this->app->useEnvironmentPath($directory);
    Http::fake(['*' => Http::response(['message' => 'invalid'], 422)]);

    $this->artisan('reel:install', [
        '--url' => 'https://reel.example',
        '--application' => 'app-public-id',
        '--enrollment-code' => 'bad-code',
    ])->assertFailed();

    expect(file_get_contents($directory.'/.env'))->toBe("UNCHANGED=yes\n");

    unlink($directory.'/.env');
    rmdir($directory);
});
