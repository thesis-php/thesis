<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * A timer of {@see VirtualTimeDriver}: kept by the driver, not by the inner one.
 *
 * @internal
 */
final class VirtualTimer
{
    public bool $enabled = true;

    public bool $referenced = true;

    /**
     * when it is due, in nanoseconds of the clock, while it waits in the driver's queue.
     */
    public int $expiration = 0;

    /**
     * outdates the entries of the driver's queue that do not match.
     */
    public int $order = 0;

    /**
     * the inner defer that fires the timer once it is due.
     */
    public ?string $firingCallbackId = null;

    /**
     * @param int $interval in nanoseconds
     * @param \Closure(string): mixed $callback
     */
    public function __construct(
        public readonly string $callbackId,
        public readonly int $interval,
        public readonly \Closure $callback,
        public readonly bool $repeat,
    ) {}
}
