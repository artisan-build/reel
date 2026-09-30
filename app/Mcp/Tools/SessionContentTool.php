<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\McpInput;
use App\Mcp\Support\McpResponse;
use App\Mcp\Support\OpaqueCursor;
use App\Models\Application;
use App\Services\ReplayPayloadReader;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('session_content')]
#[Description('Read bounded pages of privacy-validated DOM snapshots/mutations, clicks, scrolls, and 5xx markers for one selected application session.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class SessionContentTool extends Tool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
        AdvertisesToolClassification::toArray as private advertisedArray;
    }
    use RespectsEffectCeiling;

    private const array TYPES = ['dom', 'click', 'scroll', 'error'];

    private const int MAX_FRAGMENT_BYTES = 240_000;

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        $tool = $this->advertisedArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'application' => $schema->string()->min(1)->max(64)->required(),
            'session_id' => $schema->string()->pattern('^[a-f0-9]{64}$')->required(),
            'types' => $schema->array()->items($schema->string()->enum(self::TYPES))->min(1)->max(4)->default(self::TYPES),
            'cursor' => $schema->string()->min(1)->max(2048),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }

    public function handle(Request $request, ReplayPayloadReader $reader): ResponseFactory
    {
        McpInput::closed($request, ['application', 'session_id', 'types', 'cursor', 'limit']);
        $applicationId = McpInput::requiredString($request, 'application', 64);
        $sessionId = McpInput::requiredString($request, 'session_id', 64, '/^[a-f0-9]{64}$/');
        $types = McpInput::stringList($request, 'types', self::TYPES, self::TYPES, 4);
        sort($types);
        $limit = McpInput::integer($request, 'limit', 25, 1, 100);
        $application = Application::query()->where('public_id', $applicationId)->first();
        $session = $application?->recordingSessions()->where('session_id', $sessionId)->first();

        if ($application === null || $session === null) {
            return McpResponse::structured($this, ['error' => 'session_not_found']);
        }

        $scope = hash('sha256', json_encode([
            $applicationId,
            $sessionId,
            $types,
            $session->manifest_checksum,
        ], JSON_THROW_ON_ERROR));
        $cursor = OpaqueCursor::decode(McpInput::optionalString($request, 'cursor', 2048), 'content', $scope);
        $eventOffset = $cursor['event_offset'] ?? 0;
        $fragmentOffset = $cursor['fragment_offset'] ?? 0;

        if (! is_int($eventOffset) || $eventOffset < 0
            || ! is_int($fragmentOffset) || $fragmentOffset < 0) {
            McpInput::fail('cursor', 'The cursor is invalid.');
        }

        $session->setRelation('application', $application);
        $payload = $reader->read($session);

        if ($payload->diagnostic !== null) {
            return McpResponse::structured($this, [
                'session_id' => $sessionId,
                'diagnostic' => $payload->diagnostic,
                'events' => [],
                'next_cursor' => null,
            ]);
        }

        $index = $this->nextSelectedIndex($payload->events, $eventOffset, $types);

        if ($fragmentOffset > 0 && $index !== $eventOffset) {
            McpInput::fail('cursor', 'The cursor is invalid.');
        }

        $events = [];

        while ($index !== null) {
            $event = $payload->events[$index];
            $type = $this->contentType($event);
            $nextIndex = $this->nextSelectedIndex($payload->events, $index + 1, $types);
            $nextCursor = $nextIndex === null ? null : $this->cursor($scope, $nextIndex);
            $eventJson = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($fragmentOffset > 0) {
                return McpResponse::structured($this, $this->fragmentPage(
                    $sessionId,
                    $scope,
                    $index,
                    $type,
                    $eventJson,
                    $fragmentOffset,
                    $nextIndex,
                ));
            }

            $entry = ['sequence' => $index, 'kind' => $type, 'event' => $event];
            $candidate = $this->page($sessionId, [...$events, $entry], $nextCursor);

            if (! McpResponse::fits($this, $candidate)) {
                if ($events !== []) {
                    return McpResponse::structured($this, $this->page($sessionId, $events, $this->cursor($scope, $index)));
                }

                return McpResponse::structured($this, $this->fragmentPage(
                    $sessionId,
                    $scope,
                    $index,
                    $type,
                    $eventJson,
                    0,
                    $nextIndex,
                ));
            }

            $events[] = $entry;

            if (count($events) === $limit || $nextIndex === null) {
                return McpResponse::structured($this, $candidate);
            }

            $index = $nextIndex;
        }

        return McpResponse::structured($this, $this->page($sessionId, [], null));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<string>  $types
     */
    private function nextSelectedIndex(array $events, int $offset, array $types): ?int
    {
        for ($index = $offset; $index < count($events); $index++) {
            $type = $this->contentType($events[$index]);

            if ($type !== null && in_array($type, $types, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function page(string $sessionId, array $events, ?string $nextCursor): array
    {
        return [
            'session_id' => $sessionId,
            'diagnostic' => null,
            'events' => $events,
            'next_cursor' => $nextCursor,
        ];
    }

    /** @return array<string, mixed> */
    private function fragmentPage(
        string $sessionId,
        string $scope,
        int $sequence,
        string $kind,
        string $eventJson,
        int $offset,
        ?int $nextEventOffset,
    ): array {
        $total = strlen($eventJson);

        if ($offset >= $total) {
            McpInput::fail('cursor', 'The cursor is invalid.');
        }

        $length = min(self::MAX_FRAGMENT_BYTES, $total - $offset);

        while ($length > 0) {
            $nextOffset = $offset + $length;
            $nextCursor = $nextOffset < $total
                ? $this->cursor($scope, $sequence, $nextOffset)
                : ($nextEventOffset === null ? null : $this->cursor($scope, $nextEventOffset));
            $page = $this->page($sessionId, [[
                'sequence' => $sequence,
                'kind' => $kind,
                'encoding' => 'base64',
                'fragment_offset' => $offset,
                'fragment_total' => $total,
                'fragment' => base64_encode(substr($eventJson, $offset, $length)),
            ]], $nextCursor);

            if (McpResponse::fits($this, $page)) {
                return $page;
            }

            $length = intdiv($length, 2);
        }

        McpInput::fail('cursor', 'The response cannot be represented within the relay limit.');
    }

    private function cursor(string $scope, int $eventOffset, int $fragmentOffset = 0): string
    {
        return OpaqueCursor::encode([
            'kind' => 'content',
            'scope' => $scope,
            'event_offset' => $eventOffset,
            'fragment_offset' => $fragmentOffset,
        ]);
    }

    /** @param array<string, mixed> $event */
    private function contentType(array $event): ?string
    {
        if (($event['type'] ?? null) === 2) {
            return 'dom';
        }

        if (($event['type'] ?? null) === 5) {
            return 'error';
        }

        if (($event['type'] ?? null) !== 3 || ! is_array($event['data'] ?? null)) {
            return null;
        }

        return match ($event['data']['source'] ?? null) {
            0 => 'dom',
            2 => 'click',
            3 => 'scroll',
            default => null,
        };
    }
}
