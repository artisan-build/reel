<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Connectors\ConnectorInterface;

final readonly class ManagedQueueConnector implements ConnectorInterface
{
    public function __construct(private ConnectionResolverInterface $connections) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): ManagedQueue
    {
        return new ManagedQueue(
            $this->connections->connection($config['connection'] ?? null),
            (string) $config['table'],
            (string) $config['queue'],
            (int) ($config['retry_after'] ?? 60),
        );
    }
}
