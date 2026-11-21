<?php

declare(strict_types=1);

namespace Thesis\Async;

use Random\Randomizer;
use Time\Duration;
use function Thesis\Time\ns;
use function Thesis\Time\toNs;

/**
 * The delay starts at $initial and doubles after each attempt until it reaches $max.
 * It only computes them: the caller waits as its attempts require, with `delay()`, `StreamWatcher::delay()`
 * or a `timeoutOr()` that a close ends.
 *
 *     $backoff = new Backoff(initial: ms(100), max: sec(30));
 *
 *     while (true) {
 *         try {
 *             return connect();
 *         } catch (ConnectionFailed) {
 *             delay($backoff->next());
 *         }
 *     }
 *
 * With jitter (the default), a delay is a random one between half and all of it, so that clients that failed
 * at once do not come back at once either.
 *
 * @api
 */
final class Backoff
{
    private readonly int $initial;

    private readonly int $max;

    private int $current;

    /**
     * @param Duration $initial the first delay, positive
     * @param Duration $max no less than $initial
     * @param ?Randomizer $randomizer of the jitter, a seeded one in tests
     * @throws \ValueError if $initial is not positive or $max is less than it
     */
    public function __construct(
        Duration $initial,
        Duration $max,
        private readonly bool $jitter = true,
        private readonly ?Randomizer $randomizer = null,
    ) {
        $this->initial = toNs($initial);
        $this->max = toNs($max);

        if ($this->initial <= 0) {
            throw new \ValueError('The initial delay of a backoff must be positive.');
        }

        if ($this->max < $this->initial) {
            throw new \ValueError('The maximum delay of a backoff must not be less than the initial one.');
        }

        $this->current = $this->initial;
    }

    /**
     * Returns the delay before the next attempt.
     */
    public function next(): Duration
    {
        $delay = $this->current;
        $this->current = $delay > intdiv($this->max, 2) ? $this->max : $delay * 2;

        if (!$this->jitter) {
            return ns($delay);
        }

        $randomizer = $this->randomizer ?? new Randomizer();

        return ns($randomizer->getInt(intdiv($delay, 2), $delay));
    }

    /**
     * After a success: the next delay is the initial one again.
     */
    public function reset(): void
    {
        $this->current = $this->initial;
    }
}
