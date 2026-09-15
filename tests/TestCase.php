<?php

namespace Tests;

use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
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
        $canonicalUser = $user instanceof User
            ? User::query()->findOrFail($user->getAuthIdentifier())
            : $user;

        parent::actingAs($canonicalUser, $guard);

        return $this->withSession([
            StandaloneAccess::SESSION_VERSION_KEY => (int) data_get($canonicalUser, 'auth_session_version', 1),
        ]);
    }
}
