<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Thesis\Async\Cancelled;
use Thesis\Async\TimedOut;
use Thesis\Async\Trace\CancelCause;
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
        Scope::$tracer?->timeoutCreated($scope, $parent, $duration);
        $timer = null;

        if ($timeout > 0) {
            $timer = EventLoop::delay($timeout / 1e9, static function () use ($scope) {
                $scope->cancel(CancelCause::Deadline, by: $scope);
            });
        } else {
            // Revolt rejects a negative delay, and a zero one would let $body wait until the next tick.
            $scope->cancel(CancelCause::Deadline, by: $scope);
        }

        try {
            $result = $scope->run($body);
            Scope::$tracer?->ended($scope, $result, null);

            return $result;
        } catch (Cancelled $cancelled) {
            Scope::$tracer?->ended($scope, null, $cancelled);

            // Only the timer cancels this scope, so if the parent is not cancelled, the deadline is the reason.
            if ($scope->isCancelled() && !$parent->isCancelled()) {
                return new self($cancelled);
            }

            throw $cancelled;
        } catch (\Throwable $error) {
            Scope::$tracer?->ended($scope, null, $error);

            throw $error;
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
