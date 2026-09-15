<?php

namespace App\Livewire\Applications;

use App\Models\Application;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Applications')]
class Index extends Component
{
    public function mount(): void
    {
        abort_unless(app(IdentityContext::class)->canUseProduct(), 403);
    }

    /**
     * @return Collection<int, Application>
     */
    #[Computed]
    public function applications(): Collection
    {
        abort_unless(app(IdentityContext::class)->canUseProduct(), 403);

        return Application::query()->latest()->get();
    }
}
