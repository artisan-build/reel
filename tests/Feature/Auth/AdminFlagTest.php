<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

it('adds a guarded boolean admin flag defaulting to false', function (): void {
    expect(Schema::hasColumn('users', 'is_admin'))->toBeTrue();

    $user = User::factory()->create();

    $this->assertSame(false, $user->is_admin);
    // PostgreSQL PDO already returns native booleans, so assert the explicit cast for portability.
    $this->assertSame('boolean', (new User)->getCasts()['is_admin'] ?? null);

    $guardedUser = User::query()->create([
        'name' => 'Guarded User',
        'email' => 'guarded@example.com',
        'password' => 'password',
        'is_admin' => true,
    ]);
    $guardedUser->refresh();

    $this->assertSame(false, $guardedUser->is_admin);
    $this->assertNotSame(true, (new User)->fill(['is_admin' => true])->is_admin);

    $guardedUser->is_admin = true;
    $guardedUser->save();

    $this->assertSame(true, $guardedUser->refresh()->is_admin);
    $this->assertSame(true, User::factory()->admin()->create()->is_admin);

    $forced = User::forceCreate([
        'name' => 'Forced Admin',
        'email' => 'forced@example.com',
        'password' => 'password',
        'is_admin' => true,
    ]);

    $this->assertSame(true, $forced->refresh()->is_admin);
    $this->assertNotContains('is_admin', (new User)->getFillable());
});

it('creates the first administrator without persisting a bootstrap secret', function (): void {
    config(['built-for-cloud.fallback_token' => null]);

    $environmentPath = app()->environmentFilePath();
    $environmentBefore = file_exists($environmentPath) ? file_get_contents($environmentPath) : null;
    $configBefore = config('built-for-cloud');

    $exitCode = Artisan::call('create-admin', [
        '--execute' => true,
        '--email' => 'admin@example.com',
        '--password' => 'bootstrap-password',
        '--name' => 'Administrator',
    ]);

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($admin->is_admin)->toBeTrue()
        ->and(Hash::check('bootstrap-password', $admin->password))->toBeTrue()
        ->and(config('built-for-cloud.fallback_token'))->toBeNull()
        ->and(config('built-for-cloud'))->toBe($configBefore)
        ->and(file_exists($environmentPath) ? file_get_contents($environmentPath) : null)->toBe($environmentBefore)
        ->and(file_get_contents(base_path('.env.example')))->not->toMatch('/^FALLBACK_TOKEN=/m');
});
