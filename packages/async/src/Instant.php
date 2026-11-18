<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\Scope;
use Time\Duration;
use Time\TimeException;
use function Thesis\Time\ns;
use function Thesis\Time\toNs;

/**
 * A point on the monotonic clock of the library: the clock of deadlines and of the timers of the event loop,
 * which `virtualTime()` replaces. For measuring and for a time kept ahead, such as the expiry of a cached entry:
 * code that keeps times takes them from here, not from `hrtime()`, or virtual time does not move them.
 *
 * Not wall-clock time: only instants of one process and of one `virtualTime()` make sense together.
 * Instants compare with the operators: `$expiresAt <= Instant::now()`.
 * A point in time, not a `Time\Duration`: the difference of two instants is one.
 *
 * @api
 */
final readonly class Instant
{
    public static function now(): self
    {
        return new self(Scope::now());
    }

    /**
     * @param int $nanoseconds since the unknown origin of the clock, as `Internal\Scope::now()`
     */
    private function __construct(
        private int $nanoseconds,
    ) {}

    /**
     * How long $fn takes, waits included. Its result is dropped: capture it by reference if needed.
     *
     * @param callable(): mixed $fn
     */
    public static function elapsed(callable $fn): Duration
    {
        $start = self::now();
        $fn();

        return self::now()->since($start);
    }

    /**
     * @throws TimeException if the instant is out of the range of the clock, about 292 years from its origin
     */
    public function add(Duration $duration): self
    {
        $sum = $this->nanoseconds + toNs($duration);

        // @phpstan-ignore function.alreadyNarrowedType (an int overflow turns the sum into a float)
        if (!\is_int($sum)) {
            throw new TimeException('The instant is out of the range of the clock (roughly 292 years from its origin)');
        }

        return new self($sum);
    }

    /**
     * The time from $earlier to this instant, negative if $earlier is later:
     * `Instant::now()->since($start)` is the time elapsed, `$deadline->since(Instant::now())` the time left.
     *
     * @throws TimeException if the time is out of the range of `Time\Duration`, about 292 years
     */
    public function since(self $earlier): Duration
    {
        $difference = $this->nanoseconds - $earlier->nanoseconds;

        // Beyond int, the difference is beyond Duration too: the same exception as ns() throws within int.
        // @phpstan-ignore function.alreadyNarrowedType (an int overflow turns the difference into a float)
        if (!\is_int($difference)) {
            throw new TimeException('The maximum representable range is 9_223_372_035 seconds (roughly 292 years)');
        }

        return ns($difference);
    }
}
