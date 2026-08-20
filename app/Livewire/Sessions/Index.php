<?php

namespace App\Livewire\Sessions;

use App\Enums\RecordingSessionStatus;
use App\Models\Application;
use App\Models\RecordingMarker;
use App\Models\RecordingSession;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Title('Sessions')]
class Index extends Component
{
    use WithPagination;

    #[Url(history: true)]
    public string $startedFrom = '';

    #[Url(history: true)]
    public string $startedTo = '';

    #[Url(history: true)]
    public string $endedFrom = '';

    #[Url(history: true)]
    public string $endedTo = '';

    #[Url(history: true)]
    public string $durationMin = '';

    #[Url(history: true)]
    public string $durationMax = '';

    #[Url(as: 'application', history: true)]
    public string $applicationId = '';

    #[Url(history: true)]
    public string $path = '';

    #[Url(as: 'session_id', history: true)]
    public string $sessionId = '';

    #[Url(as: 'user_id', history: true)]
    public string $applicationUserId = '';

    #[Url(as: 'release', history: true)]
    public string $releaseId = '';

    #[Url(history: true)]
    public string $status = '';

    #[Url(as: 'marker', history: true)]
    public string $markerType = '';

    #[Url(history: true)]
    public string $protected = '';

    #[Url(history: true)]
    public string $watched = '';

    public function mount(?Application $application = null): void
    {
        if ($application instanceof Application) {
            $this->applicationId = $application->public_id;
        }
    }

    public function render(): View
    {
        return view('livewire.sessions.index');
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /** @return Collection<int, Application> */
    #[Computed]
    public function applications(): Collection
    {
        return Application::query()->orderBy('name')->get(['id', 'public_id', 'name']);
    }

    /** @return list<string> */
    #[Computed]
    public function markerTypes(): array
    {
        return RecordingMarker::query()
            ->distinct()
            ->orderBy('marker_type')
            ->pluck('marker_type')
            ->all();
    }

    /** @return list<RecordingSessionStatus> */
    #[Computed]
    public function statuses(): array
    {
        return RecordingSessionStatus::cases();
    }

    /** @return LengthAwarePaginator<int, RecordingSession> */
    #[Computed]
    public function sessions(): LengthAwarePaginator
    {
        $viewerId = (int) auth()->id();
        $query = RecordingSession::query()->with('application');

        $this->applyDateFilter($query, 'started_at', '>=', $this->startedFrom);
        $this->applyDateFilter($query, 'started_at', '<=', $this->startedTo);
        $this->applyDateFilter($query, 'ended_at', '>=', $this->endedFrom);
        $this->applyDateFilter($query, 'ended_at', '<=', $this->endedTo);
        $this->applyIntegerFilter($query, 'duration_seconds', '>=', $this->durationMin);
        $this->applyIntegerFilter($query, 'duration_seconds', '<=', $this->durationMax);

        $query->when($this->applicationId !== '', function (Builder $query): void {
            $query->whereHas('application', fn (Builder $application): Builder => $application
                ->where('public_id', $this->applicationId));
        });
        $query->when($this->path !== '', function (Builder $query): void {
            $query->where(function (Builder $paths): void {
                $paths->where('initial_path', $this->path)
                    ->orWhere('latest_path', $this->path);
            });
        });
        $query->when($this->sessionId !== '', fn (Builder $query): Builder => $query
            ->where('session_id', $this->sessionId));
        $query->when($this->applicationUserId !== '', fn (Builder $query): Builder => $query
            ->where('application_user_id', $this->applicationUserId));
        $query->when($this->releaseId !== '', fn (Builder $query): Builder => $query
            ->where('release_id', $this->releaseId));
        $query->when(
            RecordingSessionStatus::tryFrom($this->status) instanceof RecordingSessionStatus,
            fn (Builder $query): Builder => $query->where('status', $this->status),
        );
        $query->when($this->markerType !== '', fn (Builder $query): Builder => $query
            ->whereHas('markers', fn (Builder $markers): Builder => $markers
                ->where('marker_type', $this->markerType)));
        $query->when($this->protected === 'yes', fn (Builder $query): Builder => $query
            ->whereNotNull('protected_at'));
        $query->when($this->protected === 'no', fn (Builder $query): Builder => $query
            ->whereNull('protected_at'));
        $query->when($this->watched === 'yes', fn (Builder $query): Builder => $query
            ->whereHas('replayViews', fn (Builder $views): Builder => $views
                ->where('user_id', $viewerId)));
        $query->when($this->watched === 'no', fn (Builder $query): Builder => $query
            ->whereDoesntHave('replayViews', fn (Builder $views): Builder => $views
                ->where('user_id', $viewerId)));

        return $query->orderByDesc('started_at')->paginate(25);
    }

    /** @param Builder<RecordingSession> $query */
    private function applyDateFilter(Builder $query, string $column, string $operator, string $value): void
    {
        if ($value === '') {
            return;
        }

        try {
            $date = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return;
        }

        $query->where($column, $operator, $date);
    }

    /** @param Builder<RecordingSession> $query */
    private function applyIntegerFilter(Builder $query, string $column, string $operator, string $value): void
    {
        if ($value !== '' && ctype_digit($value)) {
            $query->where($column, $operator, (int) $value);
        }
    }
}
