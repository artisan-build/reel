<?php

namespace App\Livewire\Applications;

use App\Models\Application;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Applications')]
class Index extends Component
{
    public function mount(): void
    {
        abort_unless(resolve(IdentityContext::class)->canUseProduct(), 403);
    }

    public function render(): View
    {
        return view('livewire.applications.index');
    }

    /**
     * @return Collection<int, Application>
     */
    #[Computed]
    public function applications(): Collection
    {
        abort_unless(resolve(IdentityContext::class)->canUseProduct(), 403);

        return Application::query()->latest()->get();
    }
}
