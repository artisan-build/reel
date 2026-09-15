<?php

namespace Tests;

use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        DB::table('bfc_authority')->updateOrInsert(
            ['key' => 'installation'],
            ['mode' => 'standalone', 'generation' => 1, 'updated_at' => now(), 'created_at' => now()],
        );
        parent::actingAs($user, $guard);

        return $this->withSession([
            StandaloneAccess::SESSION_VERSION_KEY => (int) data_get($user, 'auth_session_version', 1),
        ]);
    }
}
