<?php

declare(strict_types=1);

namespace Thesis\Async\Benchmarks;

use Tally\Attribute\Expected;
use Tally\Attribute\Warmup;
use function Thesis\Async\map;

#[Warmup(1)]
final class WorkersBench
{
    #[Expected(instructions: '4.560M', cycles: '4.635M', memory: '79.28KiB')]
    public function benchSequentialGenerator(): void
    {
        map(self::items(), 1, abs(...));
    }

    #[Expected(instructions: '17.84M', cycles: '24.38M', memory: '82.30KiB')]
    public function benchConcurrentGenerator(): void
    {
        map(self::items(), 4, abs(...));
    }

    #[Expected(instructions: '4.423M', cycles: '4.508M', memory: '98.87KiB')]
    public function benchSequentialArray(): void
    {
        map(range(1, 1000), 1, abs(...));
    }

    /**
     * @return \Generator<int, int>
     */
    private static function items(): \Generator
    {
        for ($i = 0; $i < 1000; ++$i) {
            yield $i;
        }
    }
}
