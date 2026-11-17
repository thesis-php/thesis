<?php

declare(strict_types=1);

namespace Thesis\Async\Benchmarks;

use Tally\Attribute\Expected;
use Tally\Attribute\Warmup;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Time\sec;

/**
 * Almost every timer of timeout() is cancelled before it fires: creating and cancelling one must be cheap,
 * and the cancelled ones must not pile up.
 */
#[Warmup(1)]
final class TimeoutBench
{
    #[Expected(instructions: '201.8M', cycles: '293.1M', memory: '3.438KiB')]
    public function benchCancelledBeforeFiring(): void
    {
        group(static function () {
            for ($i = 0; $i < 10_000; ++$i) {
                timeout(sec(10), static fn() => null);
            }
        });
    }
}
