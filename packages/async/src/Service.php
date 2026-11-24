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
     * Requests shutdown and waits for all background work owned by the service to finish.
     * Call from outside that work while its group can still run. May be called repeatedly.
     *
     * @throws \Throwable if shutdown fails or the background task being joined failed or was cancelled
     */
    public function stop(): void;
}
