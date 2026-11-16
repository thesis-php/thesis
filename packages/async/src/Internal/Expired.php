<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Thesis\Async\Cancelled;
use Thesis\Async\TimedOut;
use Time\Duration;
use function Thesis\Time\toNs;

/**
 * Runs a body under a deadline of its own and reports the expiry of exactly that deadline:
 * the core of `Thesis\Async\timeout()` and `Thesis\Async\timeoutOr()`.
 *
 * Returned, not thrown: catching {@see TimedOut} instead would also swallow one that a nested timeout
 * let out of the body, and would build a trace only to drop it.
 *
 * @internal
 */
final readonly class Expired
{
    /**
     * @template T
     * @param callable(): T $body
     * @return T|self self only if the deadline cut $body short and the parent is not cancelled
     */
    public static function run(Duration $duration, callable $body): mixed
    {
        $timeout = toNs($duration);
        $parent = Scope::current();
        $scope = new Scope($parent, timeout: $timeout);
        $timer = null;

        if ($timeout > 0) {
            $timer = EventLoop::delay($timeout / 1e9, static function () use ($scope) {
                $scope->cancel();
            });
        } else {
            // Revolt rejects a negative delay, and a zero one would let $body wait until the next tick.
            $scope->cancel();
        }

        try {
            return $scope->run($body);
        } catch (Cancelled $cancelled) {
            // Only the timer cancels this scope, so if the parent is not cancelled, the deadline is the reason.
            if ($scope->isCancelled() && !$parent->isCancelled()) {
                return new self($cancelled);
            }

            throw $cancelled;
        } finally {
            if ($timer !== null) {
                EventLoop::cancel($timer);
            }

            $scope->close();
        }
    }

    private function __construct(
        public Cancelled $cancelled,
    ) {}
}
