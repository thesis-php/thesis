<?php

declare(strict_types=1);

/**
 * Helpers shared by the tests. Every test runs in {main}: the library drives the Revolt loop from there itself.
 * Every test runs on virtual time (VirtualTimePlugin in testo.php): durations are exact, and CPU work takes none.
 */

namespace Thesis\Async\Tests;

use Revolt\EventLoop;
use Testo\Assert;
use Thesis\Async\Cancelled;
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
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException(message: $message, severity: $severity, filename: $file, line: $line);
    });

    try {
        return $fn();
    } finally {
        restore_error_handler();
    }
}

/**
 * Runs $fn and measures how long it took on the clock of deadlines. What $fn throws goes through:
 * a test that expects an exception catches it inside $fn with thrown() and checks it.
 *
 * @param callable(): mixed $fn
 * @return float seconds
 */
function elapsed(callable $fn): float
{
    $start = now();
    $fn();

    return now() - $start;
}

/**
 * No timer, defer or watcher registered by the library outlives the wait it was registered for.
 */
function assertNoCallbacksLeft(): void
{
    Assert::same(EventLoop::getIdentifiers(), []);
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
