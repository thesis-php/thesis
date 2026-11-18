<?php

declare(strict_types=1);

/**
 * Helpers shared by the tests. Every test runs on virtual time (VirtualTimePlugin in testoPlugins of package.php),
 * in the fiber of its event loop and in the root scope: durations are exact, and CPU work takes none.
 */

namespace Thesis\Async\Tests;

use Testo\Assert;
use Thesis\Async\Cancelled;
use Thesis\Async\Continuation;
use Thesis\Async\Instant;
use Thesis\Async\Internal\Callback;
use Thesis\Async\Internal\Loop;
use Time\Duration;
use function Thesis\Async\suspend;
use function Thesis\Time\toNs;
use function Thesis\Time\toSecFloat;

/**
 * A wait that only a cancellation ends. It registers nothing, so it does not keep the loop alive:
 * if the cancellation never comes, the loop throws Deadlock into the wait.
 */
function never(): never
{
    suspend(
        /** @param Continuation<null> $_ */
        register: static fn(Continuation $_) => static function () {},
    );

    throw new \LogicException('A wait without an event source has returned.');
}

/**
 * Waits until the current scope is cancelled and returns: the code after it runs in a cancelled scope,
 * where every wait throws Cancelled again.
 */
function untilCancelled(): void
{
    try {
        never();
    } catch (Cancelled) {
    }
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
 * Runs $fn with every PHP warning, notice or deprecation thrown as an \ErrorException: for a test that
 * a message is built without one (PHP 8.6 warns when a NAN is formatted into a string).
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function withoutWarnings(callable $fn): mixed
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line) {
        throw new \ErrorException(message: $message, severity: $severity, filename: $file, line: $line);
    });

    try {
        return $fn();
    } finally {
        restore_error_handler();
    }
}

/**
 * Instant::elapsed() in float seconds, so that a test asserts it with Assert::same(). What $fn throws goes through:
 * a test that expects an exception catches it inside $fn with thrown() and checks it.
 *
 * @param callable(): mixed $fn
 */
function elapsed(callable $fn): float
{
    return toSecFloat(Instant::elapsed($fn));
}

/**
 * The time elapsed since $start in float seconds, so that a test asserts it with Assert::same().
 */
function secondsSince(Instant $start): float
{
    return toSecFloat(Instant::now()->since($start));
}

/**
 * Calls $callback from the event loop after $delay, outside any fiber: a callback of the loop itself, such as
 * a timer of a library on the loop would have. It keeps the loop alive, unless $referenced is false.
 *
 * @param callable(): void $callback
 * @return \Closure(): void cancels the call
 */
function later(Duration $delay, callable $callback, bool $referenced = true): \Closure
{
    $loop = Loop::get();
    $timer = $loop->delay(toNs($delay), new Callback($callback(...)), $referenced);

    return static function () use ($loop, $timer) {
        $loop->cancel($timer);
    };
}

/**
 * No timer, deferred wake, stream wait, signal handler or microtask registered by the library outlives the wait
 * it was registered for.
 */
function assertNoCallbacksLeft(): void
{
    Assert::true(Loop::get()->isIdle, 'Something is left in the event loop.');
}

/**
 * Whether $target is reachable from $from through strong references: properties, array items, the elements
 * of SPL containers, and the bound object and variables of closures. Weak references and fibers' stacks are not walked.
 *
 * A leak test that drops its own references and expects a WeakReference to clear depends on the environment:
 * Xdebug's develop mode keeps the arguments of the stack of the last thrown exceptions. This one does not.
 */
function reaches(object $from, object $target): bool
{
    /** @var \SplObjectStorage<object, null> */
    $visited = new \SplObjectStorage();
    /** @var list<mixed> */
    $stack = [$from];

    while ($stack !== []) {
        $node = array_pop($stack);

        if (\is_array($node)) {
            array_push($stack, ...array_values($node));

            continue;
        }

        if (!\is_object($node) || $visited->offsetExists($node)) {
            continue;
        }

        if ($node === $target) {
            return true;
        }

        $visited->offsetSet($node);

        if ($node instanceof \WeakReference || $node instanceof \WeakMap) {
            continue;
        }

        if ($node instanceof \Closure) {
            $function = new \ReflectionFunction($node);
            $stack[] = $function->getClosureThis();
            array_push($stack, ...array_values($function->getStaticVariables()));

            continue;
        }

        if ($node instanceof \SplObjectStorage) {
            foreach ($node as $object) {
                $stack[] = $object;
                $stack[] = $node[$object];
            }
        } elseif ($node instanceof \SplDoublyLinkedList || $node instanceof \SplHeap || $node instanceof \ArrayObject) {
            foreach (clone $node as $item) {
                $stack[] = $item;
            }
        }

        array_push($stack, ...array_values(get_mangled_object_vars($node)));
    }

    return false;
}
