<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\McpInput;
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
use Laravel\Mcp\Response;
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
    use AdvertisesToolClassification {
        toArray as private advertisedArray;
    }
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

    private const array TYPES = ['dom', 'click', 'scroll', 'error'];

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
        $types = McpInput::stringList($request, 'types', self::TYPES, self::TYPES);
        sort($types);
        $limit = McpInput::integer($request, 'limit', 25, 1, 100);
        $scope = hash('sha256', json_encode([$applicationId, $sessionId, $types], JSON_THROW_ON_ERROR));
        $cursor = OpaqueCursor::decode(McpInput::optionalString($request, 'cursor', 2048), 'content', $scope);
        $offset = $cursor['offset'] ?? 0;

        if (! is_int($offset) || $offset < 0) {
            McpInput::fail('cursor', 'The cursor is invalid.');
        }

        $application = Application::query()->where('public_id', $applicationId)->first();
        $session = $application?->recordingSessions()->where('session_id', $sessionId)->first();

        if ($application === null || $session === null) {
            return Response::structured(['error' => 'session_not_found']);
        }

        $session->setRelation('application', $application);
        $payload = $reader->read($session);

        if ($payload->diagnostic !== null) {
            return Response::structured([
                'session_id' => $sessionId,
                'diagnostic' => $payload->diagnostic,
                'events' => [],
                'next_cursor' => null,
            ]);
        }

        $events = [];
        $nextOffset = count($payload->events);

        for ($index = $offset; $index < count($payload->events); $index++) {
            $type = $this->contentType($payload->events[$index]);

            if ($type === null || ! in_array($type, $types, true)) {
                continue;
            }

            if (count($events) === $limit) {
                $nextOffset = $index;
                break;
            }

            $events[] = [
                'sequence' => $index,
                'kind' => $type,
                'event' => $payload->events[$index],
            ];
        }

        return Response::structured([
            'session_id' => $sessionId,
            'diagnostic' => null,
            'events' => $events,
            'next_cursor' => $nextOffset < count($payload->events)
                ? OpaqueCursor::encode(['kind' => 'content', 'scope' => $scope, 'offset' => $nextOffset])
                : null,
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
