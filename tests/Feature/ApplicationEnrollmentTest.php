<?php

declare(strict_types=1);

use App\Enums\CredentialStatus;
use App\Models\Application;
use App\Models\ApplicationCredential;
use App\Services\EnrollmentCodeIssuer;
use Illuminate\Support\Facades\Hash;

it('enrolls one public key with a hashed single use code', function (): void {
    $application = Application::factory()->create();
    $code = resolve(EnrollmentCodeIssuer::class)->issue($application);
    $credential = $application->credentials()->sole();
    $keyPair = testRsaKeyPair();

    expect($credential->enrollment_code_hash)->not->toBe($code)
        ->and(Hash::check($code, $credential->enrollment_code_hash))->toBeTrue();

    $payload = [
        'enrollment_code' => $code,
        'algorithm' => ApplicationCredential::ALGORITHM,
        'public_key' => $keyPair['public'],
    ];

    $this->postJson(route('applications.enrollment.store', $application), $payload)
        ->assertCreated()
        ->assertJsonPath('application_id', $application->public_id)
        ->assertJsonPath('algorithm', ApplicationCredential::ALGORITHM);

    $credential->refresh();

    expect($credential->public_key)->toBe(trim($keyPair['public']))
        ->and($credential->status)->toBe(CredentialStatus::Active)
        ->and($credential->enrolled_at)->not->toBeNull()
        ->and($credential->enrollment_code_hash)->toBeNull();

    $countAfterEnrollment = ApplicationCredential::query()->count();

    $this->postJson(route('applications.enrollment.store', $application), $payload)
        ->assertUnprocessable();

    expect(ApplicationCredential::query()->count())->toBe($countAfterEnrollment);
});

it('rejects an enrollment payload containing a private key', function (): void {
    $application = Application::factory()->create();
    $code = resolve(EnrollmentCodeIssuer::class)->issue($application);

    $this->postJson(route('applications.enrollment.store', $application), [
        'enrollment_code' => $code,
        'algorithm' => ApplicationCredential::ALGORITHM,
        'public_key' => testRsaKeyPair()['private'],
    ])->assertUnprocessable()->assertJsonValidationErrors('public_key');

    $credential = $application->credentials()->sole();

    expect($credential->public_key)->toBeNull()
        ->and($credential->status)->toBeNull();
});

it('rejects unexpected signing algorithms', function (): void {
    $application = Application::factory()->create();
    $code = resolve(EnrollmentCodeIssuer::class)->issue($application);

    $this->postJson(route('applications.enrollment.store', $application), [
        'enrollment_code' => $code,
        'algorithm' => 'HS256',
        'public_key' => testRsaKeyPair()['public'],
    ])->assertUnprocessable()->assertJsonValidationErrors('algorithm');

    expect($application->credentials()->sole()->status)->toBeNull();
});

it('rejects expired enrollment codes', function (): void {
    $application = Application::factory()->create();
    $code = 'expired-enrollment-code';
    ApplicationCredential::factory()->for($application)->create([
        'enrollment_code_hash' => Hash::make($code),
        'enrollment_expires_at' => now()->subSecond(),
    ]);

    $this->postJson(route('applications.enrollment.store', $application), [
        'enrollment_code' => $code,
        'algorithm' => ApplicationCredential::ALGORITHM,
        'public_key' => testRsaKeyPair()['public'],
    ])->assertUnprocessable()->assertJsonValidationErrors('enrollment_code');

    expect($application->credentials()->sole()->status)->toBeNull();
});

it('rejects enrollment for revoked credentials', function (): void {
    $application = Application::factory()->create();
    $code = 'revoked-enrollment-code';
    ApplicationCredential::factory()->for($application)->create([
        'status' => CredentialStatus::Revoked,
        'enrollment_code_hash' => Hash::make($code),
        'revoked_at' => now(),
    ]);

    $this->postJson(route('applications.enrollment.store', $application), [
        'enrollment_code' => $code,
        'algorithm' => ApplicationCredential::ALGORITHM,
        'public_key' => testRsaKeyPair()['public'],
    ])->assertUnprocessable()->assertJsonValidationErrors('enrollment_code');

    expect($application->credentials()->sole()->status)->toBe(CredentialStatus::Revoked);
});

it('fails enrollment closed while the application kill switch is disabled', function (): void {
    $application = Application::factory()->create(['ingest_enabled' => false]);
    $code = resolve(EnrollmentCodeIssuer::class)->issue($application);

    $this->postJson(route('applications.enrollment.store', $application), [
        'enrollment_code' => $code,
        'algorithm' => ApplicationCredential::ALGORITHM,
        'public_key' => testRsaKeyPair()['public'],
    ])->assertForbidden();

    expect($application->credentials()->sole()->status)->toBeNull();
});

it('does not resolve an enrollment code across application boundaries', function (): void {
    $applicationA = Application::factory()->create();
    $applicationB = Application::factory()->create();
    $codeB = resolve(EnrollmentCodeIssuer::class)->issue($applicationB);

    $this->postJson(route('applications.enrollment.store', $applicationA), [
        'enrollment_code' => $codeB,
        'algorithm' => ApplicationCredential::ALGORITHM,
        'public_key' => testRsaKeyPair()['public'],
    ])->assertUnprocessable();

    expect($applicationA->credentials()->count())->toBe(0)
        ->and($applicationB->credentials()->sole()->status)->toBeNull();
});
