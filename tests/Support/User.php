<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Factories\HasFactory;

final class User extends \ArtisanBuild\BuiltForCloud\User
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
