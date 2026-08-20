<?php

namespace App\Livewire\Applications\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait EnsuresAdministrator
{
    private function ensureAdministrator(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->is_admin, 403);
    }
}
