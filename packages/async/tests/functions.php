<?php

declare(strict_types=1);

/**
 * Helpers shared by the tests. Every test runs in {main}: the library drives the Revolt loop from there itself.
 */

namespace Thesis\Async\Tests;

use Revolt\EventLoop;
use Testo\Assert;

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
 * Runs $fn, ignoring what it throws, and measures how long it took.
 *
 * @param callable(): mixed $fn
 * @return float milliseconds
 */
function elapsed(callable $fn): float
{
    $start = hrtime(true);

    try {
        $fn();
    } catch (\Throwable) {
    }

    return (hrtime(true) - $start) / 1e6;
}

/**
 * No timer, defer or watcher registered by the library outlives the wait it was registered for.
 */
function assertNoCallbacksLeft(): void
{
    Assert::same(EventLoop::getIdentifiers(), []);
}

/**
 * Burns CPU without yielding to the event loop.
 */
function busy(float $seconds): void
{
    $until = hrtime(true) + (int) ($seconds * 1e9);

    while (hrtime(true) < $until);
}
