<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\RecordingSessionStatus;
use App\Mcp\Support\McpInput;
use App\Mcp\Support\OpaqueCursor;
use App\Models\Application;
use App\Models\RecordingSession;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('sessions')]
#[Description('Search one Reel application’s recording sessions with the same filters as the Sessions screen. Results are newest-first, cursor-paginated, and include at most ten sanitized 5xx markers per session.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class SessionsTool extends Tool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
        AdvertisesToolClassification::toArray as private advertisedArray;
    }
    use RespectsEffectCeiling;

    private const int DEFAULT_LIMIT = 25;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = $this->advertisedArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'application' => $schema->string()->min(1)->max(64)->description('Application public id.')->required(),
            'started_from' => $schema->string()->max(64),
            'started_to' => $schema->string()->max(64),
            'ended_from' => $schema->string()->max(64),
            'ended_to' => $schema->string()->max(64),
            'duration_min' => $schema->integer()->min(0),
            'duration_max' => $schema->integer()->min(0),
            'path' => $schema->string()->min(1)->max(2048),
            'session_id' => $schema->string()->pattern('^[a-f0-9]{64}$'),
            'user_id' => $schema->string()->min(1)->max(255),
            'release' => $schema->string()->min(1)->max(255),
            'status' => $schema->string()->enum(RecordingSessionStatus::class),
            'marker' => $schema->string()->min(1)->max(100),
            'protected' => $schema->string()->enum(['yes', 'no']),
            'watched' => $schema->string()->enum(['yes', 'no']),
            'cursor' => $schema->string()->min(1)->max(2048),
            'limit' => $schema->integer()->min(1)->max(100)->default(self::DEFAULT_LIMIT),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        McpInput::closed($request, [
            'application', 'started_from', 'started_to', 'ended_from', 'ended_to',
            'duration_min', 'duration_max', 'path', 'session_id', 'user_id', 'release',
            'status', 'marker', 'protected', 'watched', 'cursor', 'limit',
        ]);

        $applicationId = McpInput::requiredString($request, 'application', 64);
        $limit = McpInput::integer($request, 'limit', self::DEFAULT_LIMIT, 1, 100);
        $filters = $this->filters($request);
        $scope = hash('sha256', json_encode([$applicationId, $filters], JSON_THROW_ON_ERROR));
        $cursor = OpaqueCursor::decode(McpInput::optionalString($request, 'cursor', 2048), 'sessions', $scope);
        $application = Application::query()->where('public_id', $applicationId)->first();

        if (! $application instanceof Application) {
            return Response::structured(['error' => 'application_not_found']);
        }

        $query = $application->recordingSessions()->with('application');
        $this->applyFilters($query, $filters, $request);

        if ($cursor !== []) {
            $startedAt = $cursor['started_at'] ?? null;
            $id = $cursor['id'] ?? null;

            if (! is_string($startedAt) || ! is_int($id) || $id < 1) {
                McpInput::fail('cursor', 'The cursor is invalid.');
            }

            $query->where(function (Builder $page) use ($startedAt, $id): void {
                $page->where('started_at', '<', $startedAt)
                    ->orWhere(function (Builder $sameTime) use ($startedAt, $id): void {
                        $sameTime->where('started_at', $startedAt)->where('id', '<', $id);
                    });
            });
        }

        $rows = $query->orderByDesc('started_at')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $last = $rows->last();

        return Response::structured([
            'sessions' => $rows->map(fn (RecordingSession $session): array => $this->serializeSession($session))->all(),
            'next_cursor' => $hasMore && $last instanceof RecordingSession
                ? OpaqueCursor::encode([
                    'kind' => 'sessions',
                    'scope' => $scope,
                    'started_at' => $last->started_at->format('Y-m-d H:i:s.u'),
                    'id' => (int) $last->getKey(),
                ])
                : null,
        ]);
    }

    /** @return array<string, int|string|null> */
    private function filters(Request $request): array
    {
        $filters = [
            'started_from' => McpInput::optionalString($request, 'started_from', 64),
            'started_to' => McpInput::optionalString($request, 'started_to', 64),
            'ended_from' => McpInput::optionalString($request, 'ended_from', 64),
            'ended_to' => McpInput::optionalString($request, 'ended_to', 64),
            'duration_min' => $request->get('duration_min'),
            'duration_max' => $request->get('duration_max'),
            'path' => McpInput::optionalString($request, 'path', 2048),
            'session_id' => $request->get('session_id'),
            'user_id' => McpInput::optionalString($request, 'user_id', 255),
            'release' => McpInput::optionalString($request, 'release', 255),
            'status' => McpInput::optionalEnum($request, 'status', array_column(RecordingSessionStatus::cases(), 'value')),
            'marker' => McpInput::optionalString($request, 'marker', 100),
            'protected' => McpInput::optionalEnum($request, 'protected', ['yes', 'no']),
            'watched' => McpInput::optionalEnum($request, 'watched', ['yes', 'no']),
        ];

        foreach (['duration_min', 'duration_max'] as $key) {
            if ($filters[$key] !== null && (! is_int($filters[$key]) || $filters[$key] < 0)) {
                McpInput::fail($key, "The {$key} argument is invalid.");
            }
        }

        if ($filters['session_id'] !== null
            && (! is_string($filters['session_id']) || preg_match('/^[a-f0-9]{64}$/', $filters['session_id']) !== 1)) {
            McpInput::fail('session_id', 'The session_id argument is invalid.');
        }

        foreach (['started_from', 'started_to', 'ended_from', 'ended_to'] as $key) {
            if (is_string($filters[$key])) {
                try {
                    $filters[$key] = CarbonImmutable::parse($filters[$key])->toISOString();
                } catch (Throwable) {
                    McpInput::fail($key, "The {$key} argument is invalid.");
                }
            }
        }

        return $filters;
    }

    /**
     * @param  Builder<RecordingSession>|HasMany<RecordingSession, Application>  $query
     * @param  array<string, int|string|null>  $filters
     */
    private function applyFilters(Builder|HasMany $query, array $filters, Request $request): void
    {
        foreach ([
            ['started_at', '>=', 'started_from'], ['started_at', '<=', 'started_to'],
            ['ended_at', '>=', 'ended_from'], ['ended_at', '<=', 'ended_to'],
            ['duration_seconds', '>=', 'duration_min'], ['duration_seconds', '<=', 'duration_max'],
        ] as [$column, $operator, $key]) {
            if ($filters[$key] !== null) {
                $query->where($column, $operator, $filters[$key]);
            }
        }

        if (is_string($filters['path'])) {
            $query->where(fn (Builder $paths): Builder => $paths
                ->where('initial_path', $filters['path'])->orWhere('latest_path', $filters['path']));
        }

        foreach (['session_id' => 'session_id', 'user_id' => 'application_user_id', 'release' => 'release_id', 'status' => 'status'] as $key => $column) {
            if ($filters[$key] !== null) {
                $query->where($column, $filters[$key]);
            }
        }

        if (is_string($filters['marker'])) {
            $query->whereHas('markers', fn (Builder $markers): Builder => $markers->where('marker_type', $filters['marker']));
        }

        if ($filters['protected'] !== null) {
            $filters['protected'] === 'yes' ? $query->whereNotNull('protected_at') : $query->whereNull('protected_at');
        }

        if ($filters['watched'] !== null) {
            $actorId = (string) $request->user()?->getAuthIdentifier();

            if ($filters['watched'] === 'yes') {
                $query->whereHas('replayViews', fn (Builder $views): Builder => $views->where('actor_id', $actorId));
            } else {
                $query->whereDoesntHave('replayViews', fn (Builder $views): Builder => $views->where('actor_id', $actorId));
            }
        }
    }

    /** @return array<string, mixed> */
    private function serializeSession(RecordingSession $session): array
    {
        $markers = $session->markers()
            ->whereIn('marker_type', ['error', 'server_error'])
            ->orderBy('occurred_at')
            ->limit(11)
            ->get();

        return [
            'application' => $session->application->public_id,
            'session_id' => $session->session_id,
            'started_at' => $session->started_at->toISOString(),
            'ended_at' => $session->ended_at?->toISOString(),
            'duration_seconds' => $session->duration_seconds,
            'initial_path' => $session->initial_path,
            'latest_path' => $session->latest_path,
            'application_user_id' => $session->application_user_id,
            'release_id' => $session->release_id,
            'status' => $session->status->value,
            'protected' => $session->protected_at !== null,
            'complete' => $session->is_complete,
            'error_markers' => $markers->take(10)->map(function ($marker): array {
                $metadata = is_array($marker->metadata) ? $marker->metadata : [];

                return [
                    'type' => $marker->marker_type,
                    'occurred_at' => $marker->occurred_at,
                    'method' => is_string($metadata['method'] ?? null) ? $metadata['method'] : null,
                    'path' => is_string($metadata['path'] ?? null) ? $metadata['path'] : null,
                    'status' => is_int($metadata['status'] ?? null) ? $metadata['status'] : null,
                ];
            })->values()->all(),
            'error_markers_truncated' => $markers->count() > 10,
        ];
    }
}
