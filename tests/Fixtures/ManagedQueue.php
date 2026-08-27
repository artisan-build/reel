<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Queue\DatabaseQueue;
use RuntimeException;

/**
 * A queue connection whose queue name is a provisioned resource, not a free string.
 *
 * Laravel Cloud's managed queue gives an environment exactly one queue, named for the
 * deployment. Pushing to any other name fails, because nothing will ever create it.
 * The database and redis drivers behave the opposite way: there a queue name is just a
 * column value or a list key, so an invented name costs nothing. That difference is the
 * whole bug this fixture exists to reproduce, so the storage underneath is deliberately
 * the database queue and only the naming rule is Cloud's.
 */
final class ManagedQueue extends DatabaseQueue
{
    /**
     * @param  \UnitEnum|string|null  $queue
     */
    #[\Override]
    public function getQueue($queue): string
    {
        $resolved = parent::getQueue($queue);

        if ($resolved !== $this->default) {
            throw new RuntimeException("Managed queue [{$resolved}] does not exist.");
        }

        return $resolved;
    }
}
