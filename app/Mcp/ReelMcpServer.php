<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\SessionContentTool;
use App\Mcp\Tools\SessionDeepLinkTool;
use App\Mcp\Tools\SessionsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Reel')]
#[Version('1.0.0')]
#[Instructions('Read privacy-filtered recording sessions, inspect bounded replay content, and create short-lived human replay links.')]
final class ReelMcpServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        SessionsTool::class,
        SessionContentTool::class,
        SessionDeepLinkTool::class,
    ];
}
