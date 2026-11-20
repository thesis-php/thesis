<?php

declare(strict_types=1);

namespace Thesis\Async\Benchmarks;

use Tally\Attribute\Expected;
use Tally\Attribute\Warmup;
use function Thesis\Async\checkpoint;
use function Thesis\Async\group;

/**
 * A yield to the event loop for one tick, the fairness of CPU-bound code and of a Pacer.
 */
#[Warmup(1)]
final class CheckpointBench
{
    #[Expected(instructions: '144.3M', cycles: '183.7M', memory: '24.66KiB')]
    public function benchLoop(): void
    {
        group(static function () {
            for ($i = 0; $i < 10_000; ++$i) {
                checkpoint();
            }
        });
    }
}
