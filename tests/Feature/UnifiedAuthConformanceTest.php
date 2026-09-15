<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(ContractAssertions::class);

it('conforms to the package human lifecycle and auth configuration boundaries', function (): void {
    $this->assertBuiltForCloudHumanIdentityContract();
    $this->assertBuiltForCloudHumanLifecycleContract();
    $this->assertBuiltForCloudThinHostConfiguration();
});

it('keeps Reel domain code and attribution schema separate from auth and credential models', function (): void {
    $sourceRoots = [
        app_path('Http'),
        app_path('Livewire'),
        app_path('Models'),
        app_path('Services'),
        app_path('Console'),
        base_path('config'),
        base_path('database/migrations'),
        resource_path('views'),
        base_path('routes'),
    ];
    $forbidden = [
        'use App\\Models\\ApplicationCredential;',
        'use App\\Models\\User;',
        'use ArtisanBuild\\BuiltForCloud\\Credential;',
        'use ArtisanBuild\\BuiltForCloud\\User;',
        'activePublicKeysFor',
        'application_credentials',
        'is_admin',
    ];
    $violations = [];

    foreach ($sourceRoots as $root) {
        foreach (File::allFiles($root) as $file) {
            $contents = $file->getContents();

            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $violations[] = $file->getRelativePathname().'|'.$needle;
                }
            }
        }
    }

    expect($violations)->toBe([])
        ->and(is_file(app_path('Models/User.php')))->toBeFalse()
        ->and(File::glob(base_path('database/migrations/*create_users_table*.php')))->toBe([])
        ->and(is_dir(app_path('Http/Controllers/Auth')))->toBeFalse()
        ->and(is_dir(resource_path('views/auth')))->toBeFalse()
        ->and(Schema::hasTable('application_credentials'))->toBeFalse()
        ->and(Schema::getColumnListing('replay_views'))->toContain('actor_id')->not->toContain('user_id')
        ->and(Schema::getColumnListing('recording_sessions'))->toContain('protected_by', 'deletion_actor_id');
});
