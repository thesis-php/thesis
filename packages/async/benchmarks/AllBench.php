<?php

declare(strict_types=1);

namespace Thesis\Async\Benchmarks;

use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;
use function Thesis\Async\all;

/**
 * The warm-up call autoloads the classes of a group and its tasks: without it the smallest data set would
 * be mostly autoloading, and the growth from it would look smaller than it is.
 */
#[Warmup(1)]
final class AllBench
{
    /**
     * @var list<callable(): null>
     */
    private array $tasks = [];

    /**
     * @return iterable<string, array{int}>
     */
    public static function sizes(): iterable
    {
        foreach ([10, 100, 1000] as $count) {
            yield 'tasks-' . $count => [$count];
        }
    }

    #[Before]
    public function createTasks(int $count): void
    {
        $this->tasks = array_fill(0, $count, static fn() => null);
    }

    /**
     * A task costs the same however many run beside it: a scope, a fiber, a handle and a join.
     */
    #[DataProvider('sizes')]
    #[Linear('count')]
    public function benchAll(int $count): void
    {
        all($this->tasks);
    }
}
