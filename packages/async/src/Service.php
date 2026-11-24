<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * A service whose background tasks belong to an explicit group.
 *
 * @api
 */
interface Service
{
    /**
     * Starts background work in $group. On successful return, callers can use the service immediately.
     *
     * @throws \LogicException if startup is in progress or has already succeeded
     */
    public function start(Group $group): void;

    /**
     * Call while the group can still run the tasks needed for shutdown. May be called repeatedly.
     * Each service documents what has finished when stop() returns. Its group joins any remaining tasks.
     */
    public function stop(): void;
}
