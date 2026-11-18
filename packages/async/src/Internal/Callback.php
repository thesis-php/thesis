<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * A timer of {@see Loop} that calls a function instead of resuming a fiber: `Thesis\Async\Trace\watchdog()`.
 *
 * @internal
 */
final readonly class Callback implements Waiter
{
    /**
     * @param \Closure(): void $callback
     */
    public function __construct(
        private \Closure $callback,
    ) {}

    #[\Override]
    public function wake(): void
    {
        ($this->callback)();
    }
}
