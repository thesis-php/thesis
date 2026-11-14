<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Thesis\Async\Cancelled;
use Thesis\Async\TimedOut;

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
    private function __construct(
        public Cancelled $cancelled,
    ) {}

    /**
     * @template T
     * @param callable(): T $body
     * @return T|self self only if the deadline cut $body short and the parent is not cancelled
     * @throws \ValueError if $seconds is NAN
     */
    public static function run(float $seconds, callable $body): mixed
    {
        if (is_nan($seconds)) {
            throw new \ValueError('A deadline needs a number of seconds, got NAN.');
        }

        $parent = Scope::current();
        $scope = new Scope($parent, timeout: $seconds);
        $timer = null;

        if ($seconds > 0) {
            $timer = EventLoop::delay($seconds, static function () use ($scope) {
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
}
