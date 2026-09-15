<?php

declare(strict_types=1);

use App\ReelCredentialDeclaration;

return [
    'manifest' => [
        'name' => 'Reel',
        'slug' => 'reel',
        'description' => 'Customer-owned browser session replay for Laravel applications.',
        'icon' => 'https://raw.githubusercontent.com/artisan-build/reel/main/public/favicon.svg',
        'product_url' => 'https://scalpels.app/products/reel',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => ReelCredentialDeclaration::class,
        'session_guard' => null,
        'app_purposes' => [
            'reel.application.signing' => 'signing',
        ],
    ],

    'ui' => [
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => ['reel.application.signing'],
    ],
];
