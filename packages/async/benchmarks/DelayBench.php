<?php

declare(strict_types=1);

namespace Thesis\Async\Benchmarks;

use Tally\Attribute\Expected;
use Tally\Attribute\Warmup;
use Thesis\Async\Group;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Time\ms;

/**
 * Many timers at once: each task sleeps once, all of them fire within a tick or two.
 */
#[Warmup(1)]
final class DelayBench
{
    #[Expected(instructions: '68.43M', cycles: '131.8M', memory: '23.31MiB')]
    public function benchFanOut(): void
    {
        group(static function (Group $group) {
            for ($i = 0; $i < 1000; ++$i) {
                $group->spawn(static fn() => delay(ms(1)));
            }
        });
    }
}
