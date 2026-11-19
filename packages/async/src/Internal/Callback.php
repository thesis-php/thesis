<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * A function that {@see Loop} calls instead of resuming a fiber: a timer of `Thesis\Async\Trace\watchdog()`,
 * a microtask of {@see Loop::queue()}.
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
