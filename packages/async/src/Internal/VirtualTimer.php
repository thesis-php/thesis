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

    /** when it is due, while it waits in the driver's queue */
    public float $expiration = 0.0;

    /** outdates the entries of the driver's queue that do not match */
    public int $order = 0;

    /** the inner defer that fires the timer once it is due */
    public ?string $firing = null;

    /**
     * @param \Closure(string): mixed $closure
     */
    public function __construct(
        public readonly string $id,
        public readonly float $interval,
        public readonly \Closure $closure,
        public readonly bool $repeat,
    ) {}
}
