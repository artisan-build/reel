<?php

namespace App\Livewire\Applications;

use App\Models\Application;
use App\Services\RetentionDiagnostics;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Applications')]
#[Layout('layouts.app')]
class Index extends Component
{
    /** Whether the operational health modal is open. Bound to the modal. */
    public bool $showHealth = false;

    public function mount(): void
    {
        abort_unless(resolve(IdentityContext::class)->canUseProduct(), 403);
    }

    public function render(): View
    {
        return view('livewire.applications.index');
    }

    /**
     * Open the operational health modal, which is the only time the full
     * snapshot and its bucket inventory are read.
     */
    public function openHealth(): void
    {
        abort_unless(resolve(IdentityContext::class)->canUseProduct(), 403);

        $this->showHealth = true;

        unset($this->health);
    }

    /**
     * The retention and state-machine snapshot, read only while the modal is open.
     *
     * @return array<string, bool|float|int|string|null>
     */
    #[Computed]
    public function health(): array
    {
        return $this->showHealth ? resolve(RetentionDiagnostics::class)->snapshot() : [];
    }

    /** Whether orphan deletion is suspended, so the health button can say so without opening. */
    #[Computed]
    public function orphanSweeperSuspended(): bool
    {
        return resolve(RetentionDiagnostics::class)->orphanSweeperSuspended();
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
