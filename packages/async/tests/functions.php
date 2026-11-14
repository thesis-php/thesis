<?php

declare(strict_types=1);

/**
 * Helpers shared by the tests. Every test runs in {main}: the library drives the Revolt loop from there itself.
 * Every test runs on virtual time (VirtualTimePlugin in testo.php): durations are exact, and CPU work takes none.
 */

namespace Thesis\Async\Tests;

use Revolt\EventLoop;
use Testo\Assert;
use Thesis\Async\Continuation;
use function Thesis\Async\now;
use function Thesis\Async\suspend;

/**
 * A wait that only a cancellation ends. It registers nothing, so it does not keep the loop alive:
 * if the cancellation never comes, Revolt ends the loop with "Event loop terminated without resuming".
 */
function never(): never
{
    suspend(
        /** @param Continuation<null> $_ */
        static fn(Continuation $_) => static function () {},
    );

    throw new \LogicException('A wait without an event source has returned.');
}

/**
 * For tests that have to inspect side effects after an expected exception.
 * When the exception is the only outcome, use Expect::exception() instead.
 *
 * @param callable(): mixed $fn
 */
function thrown(callable $fn): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $exception) {
        return $exception;
    }

    Assert::fail('Nothing was thrown.');
}

/**
 * Runs $fn, ignoring what it throws, and measures how long it took on the clock of deadlines.
 *
 * @param callable(): mixed $fn
 * @return float seconds
 */
function elapsed(callable $fn): float
{
    $start = now();

    try {
        $fn();
    } catch (\Throwable) {
    }

    return now() - $start;
}

/**
 * No timer, defer or watcher registered by the library outlives the wait it was registered for.
 */
function assertNoCallbacksLeft(): void
{
    Assert::same(EventLoop::getIdentifiers(), []);
}
