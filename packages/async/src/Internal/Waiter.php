<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * What a timer, a stream or a deferred wake of {@see Loop} holds: the loop calls {@see wake()} once its event
 * has come, in {main}, between the fibers. A wait is a {@see ContinuationImpl}: it puts its fiber to the ready
 * queue, so the loop allocates no closure per wait.
 *
 * @internal
 */
interface Waiter
{
    /**
     * Must not wait. A throw goes to the fiber that {@see Loop::run()} runs.
     */
    public function wake(): void;
}
