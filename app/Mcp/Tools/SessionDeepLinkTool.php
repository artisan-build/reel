<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\RecordingSessionStatus;
use App\Mcp\Support\McpInput;
use App\Models\Application;
use App\Services\ReplayPlayerLink;
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

#[Name('session_deep_link')]
#[Description('Create Reel’s existing five-minute signed human replay URL. Opening it still requires Reel authentication and the response is no-store.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class SessionDeepLinkTool extends Tool
{
    use AdvertisesToolClassification {
        toArray as private advertisedArray;
    }
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

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
            'start' => $schema->integer()->min(0)->max(PHP_INT_MAX)->default(0),
        ];
    }

    public function handle(Request $request, ReplayPlayerLink $link): ResponseFactory
    {
        McpInput::closed($request, ['application', 'session_id', 'start']);
        $applicationId = McpInput::requiredString($request, 'application', 64);
        $sessionId = McpInput::requiredString($request, 'session_id', 64, '/^[a-f0-9]{64}$/');
        $start = McpInput::integer($request, 'start', 0, 0, PHP_INT_MAX);
        $application = Application::query()->where('public_id', $applicationId)->first();
        $session = $application?->recordingSessions()->where('session_id', $sessionId)->first();

        if ($application === null || $session === null) {
            return Response::structured(['error' => 'session_not_found']);
        }

        if (in_array($session->status, [RecordingSessionStatus::Deleting, RecordingSessionStatus::Deleted], true)) {
            return Response::structured(['error' => 'replay_deletion_started']);
        }

        $url = $link->make($application, $session, bin2hex(random_bytes(48)), $start);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return Response::structured([
            'url' => $url,
            'expires_at' => isset($query['expires']) ? gmdate(DATE_ATOM, (int) $query['expires']) : null,
            'authentication_required' => true,
            'cache_control' => 'no-store, private',
        ]);
    }
}
