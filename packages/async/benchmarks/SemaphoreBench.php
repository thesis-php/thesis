<?php

declare(strict_types=1);

namespace Thesis\Async\Benchmarks;

use Tally\Attribute\Expected;
use Tally\Attribute\Warmup;
use Thesis\Async\Group;
use Thesis\Async\LocalKeyedSemaphore;
use Thesis\Async\LocalSemaphore;
use function Thesis\Async\checkpoint;
use function Thesis\Async\group;

#[Warmup(1)]
final class SemaphoreBench
{
    #[Expected(instructions: '23.06M', cycles: '27.43M', memory: '2.922KiB')]
    public function benchDistinctKeys(): void
    {
        $semaphore = new LocalKeyedSemaphore(1);

        for ($i = 0; $i < 1000; ++$i) {
            $semaphore->acquire($i)->release();
        }
    }

    #[Expected(instructions: '10.26M', cycles: '11.65M', memory: '736B')]
    public function benchUncontended(): void
    {
        $semaphore = new LocalSemaphore(1);

        for ($i = 0; $i < 1000; ++$i) {
            $semaphore->acquire()->release();
        }
    }

    #[Expected(instructions: '10.40M', cycles: '11.89M', memory: '29.50KiB')]
    public function benchAfterContention(): void
    {
        $semaphore = new LocalSemaphore(1);

        group(static function (Group $group) use ($semaphore) {
            $lock = $semaphore->acquire();
            $group->spawn(static fn() => $semaphore->acquire()->release());
            checkpoint();
            $lock->release();
        });

        for ($i = 0; $i < 1000; ++$i) {
            $semaphore->acquire()->release();
        }
    }

    #[Expected(instructions: '9.145M', cycles: '13.40M', memory: '31.56KiB')]
    public function benchContended(): void
    {
        $semaphore = new LocalSemaphore(1);
        $worker = static function () use ($semaphore) {
            for ($i = 0; $i < 100; ++$i) {
                $lock = $semaphore->acquire();
                checkpoint();
                $lock->release();
            }
        };

        group(static function (Group $group) use ($worker) {
            $group->spawn($worker);
            $worker();
        });
    }
}
