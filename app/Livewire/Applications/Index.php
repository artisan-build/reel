<?php

namespace App\Livewire\Applications;

use App\Livewire\Applications\Concerns\EnsuresAdministrator;
use App\Models\Application;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Applications')]
class Index extends Component
{
    use EnsuresAdministrator;

    public function mount(): void
    {
        $this->ensureAdministrator();
    }

    /**
     * @return Collection<int, Application>
     */
    #[Computed]
    public function applications(): Collection
    {
        $this->ensureAdministrator();

        return Application::query()->latest()->get();
    }
}
