<?php

declare(strict_types=1);

namespace Thesis\Async\Internal\ClockFixture;

use function microtime as wallTime;

/**
 * @return list<float|int>
 * @internal
 */
function clocks(): array
{
    return [
        // @phpstan-ignore thesis.asyncClock (unqualified call resolves to the global clock)
        hrtime(true),
        // @phpstan-ignore thesis.asyncClock (import alias resolves to the global clock)
        wallTime(true),
        microtime(),
    ];
}

/**
 * @internal
 */
function microtime(): int
{
    return 0;
}
