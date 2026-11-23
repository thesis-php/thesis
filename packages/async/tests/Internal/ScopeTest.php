<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Cancelled;
use Thesis\Async\Trace\CancelCause;
use function Thesis\Async\checkpoint;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\timeout;
use function Thesis\Time\sec;

#[Test]
final class ScopeTest
{
    public function rootIsTheSameOutsideAnyScope(): void
    {
        $root = Scope::current();

        timeout(sec(1000), static fn() => delay(sec(1)));
        group(static fn() => null);

        Assert::same(Scope::current(), $root);
    }

    public function rootIsCurrentInFiberThatEnteredNoScope(): void
    {
        $root = Scope::current();
        $inFiber = null;

        $fiber = new \Fiber(static function () use (&$inFiber) {
            $inFiber = Scope::current();
        });
        $fiber->start();

        Assert::same($inFiber, $root);
    }

    public function rootIsCurrentAgainInFiberAfterScopeEnds(): void
    {
        $root = Scope::current();
        $inFiber = null;

        $fiber = new \Fiber(static function () use (&$inFiber) {
            timeout(sec(1000), static fn() => null);
            $inFiber = Scope::current();
        });
        $fiber->start();

        Assert::same($inFiber, $root);
    }

    public function cancellationReachesDescendantsButNotThroughShield(): void
    {
        $scope = new Scope();
        $child = new Scope($scope);
        $grandchild = new Scope($child);
        $shield = new Scope($scope, shield: true);
        $shielded = new Scope($shield);

        $scope->cancel(CancelCause::Handle, by: null);

        Assert::same(
            actual: array_map(
                callback: static fn(Scope $scope) => $scope->isCancelled,
                array: [$scope, $child, $grandchild, $shield, $shielded],
            ),
            expected: [true, true, true, false, false],
        );
    }

    public function scopeCreatedInCancelledScopeIsCancelledUnlessShield(): void
    {
        $scope = new Scope();
        $scope->cancel(CancelCause::Handle, by: null);
        $child = new Scope($scope);
        $grandchild = new Scope($child);
        $shield = new Scope($scope, shield: true);
        $shielded = new Scope($shield);

        Assert::same(
            actual: array_map(
                callback: static fn(Scope $scope) => $scope->isCancelled,
                array: [$child, $grandchild, $shield, $shielded],
            ),
            expected: [true, true, false, false],
        );
    }

    public function cancellationOfAncestorReachesScopeWithCancelledDescendant(): void
    {
        $scope = new Scope();
        $child = new Scope($scope);
        $grandchild = new Scope($child);
        $grandchild->cancel(CancelCause::Handle, by: null);
        /** @var ContinuationImpl<null> */
        $wait = new ContinuationImpl();
        $child->subscribe($wait);

        $scope->cancel(CancelCause::Handle, by: null);

        Assert::true($child->isCancelled);
        Assert::false($wait->resume());
    }

    public function subscribedWaitIsInterruptedOnCancellation(): void
    {
        $scope = new Scope();
        $child = new Scope($scope);
        /** @var ContinuationImpl<string> */
        $wait = new ContinuationImpl();
        $child->subscribe($wait);

        $scope->cancel(CancelCause::Handle, by: null);

        Assert::false($wait->resume('too late'));
        Assert::instanceOf(thrown($wait->park(...)), Cancelled::class);
    }

    public function subscriptionToCancelledScopeIsInterruptedRightAway(): void
    {
        $scope = new Scope();
        $scope->cancel(CancelCause::Handle, by: null);
        /** @var ContinuationImpl<string> */
        $wait = new ContinuationImpl();

        $scope->subscribe($wait);

        Assert::false($wait->resume('too late'));
        Assert::instanceOf(thrown($wait->park(...)), Cancelled::class);
    }

    public function unsubscribedWaitIsNotInterrupted(): void
    {
        $scope = new Scope();
        /** @var ContinuationImpl<string> */
        $wait = new ContinuationImpl();
        $scope->subscribe($wait);
        $scope->unsubscribe($wait);

        $scope->cancel(CancelCause::Handle, by: null);

        Assert::true($wait->resume('value'));
        Assert::same($wait->park(), 'value');
    }

    public function cancellationInterruptsOnlyTheRemainingWaits(): void
    {
        $scope = new Scope();
        /** @var ContinuationImpl<null> */
        $removed = new ContinuationImpl();
        /** @var ContinuationImpl<null> */
        $pending = new ContinuationImpl();
        /** @var ContinuationImpl<null> */
        $last = new ContinuationImpl();
        $scope->subscribe($removed);
        $scope->subscribe($pending);
        $scope->subscribe($removed);
        $scope->unsubscribe($removed);
        $scope->subscribe($last);

        $scope->cancel(CancelCause::Handle, by: null);

        Assert::true($removed->resume());
        Assert::false($pending->resume());
        Assert::false($last->resume());
    }

    public function throwIfCancelledThrowsOnlyInCancelledScope(): void
    {
        $scope = new Scope();
        $scope->throwIfCancelled();

        $scope->cancel(CancelCause::Handle, by: null);

        Assert::instanceOf(thrown($scope->throwIfCancelled(...)), Cancelled::class);
    }

    public function fiberSuspendedInRunGoesAwayWithLastReference(): void
    {
        $scope = new Scope();
        $fiber = new \Fiber(static function () use ($scope) {
            $scope->run(static fn() => \Fiber::suspend());
        });
        $fiber->start();
        $reference = \WeakReference::create($fiber);
        unset($fiber);

        Assert::null($reference->get());
    }

    public function rootStoresNoWaits(): void
    {
        /** @var ContinuationImpl<null> */
        $wait = new ContinuationImpl();
        $reference = \WeakReference::create($wait);

        ROOT_SCOPE->subscribe($wait);
        unset($wait);

        Assert::null($reference->get());
    }

    public function closedScopeStoresNoCallbacks(): void
    {
        $called = false;
        $scope = new Scope();
        $scope->close();

        $scope->onCancel(static function () use (&$called) {
            $called = true;
        });
        $callbacks = (fn() => $this->callbacks)->call($scope);
        $scope->cancel(CancelCause::Handle, by: null);
        checkpoint();

        Assert::same($callbacks, []);
        Assert::false($called);
    }

    public function closedCancelledScopeQueuesNoCallbacks(): void
    {
        $called = false;
        $scope = new Scope();
        $scope->cancel(CancelCause::Handle, by: null);
        $scope->close();

        $scope->onCancel(static function () use (&$called) {
            $called = true;
        });
        checkpoint();

        Assert::false($called);
    }

    public function closeOfCancelledScopeCallsQueuedCallbacksOnce(): void
    {
        $calls = 0;
        $scope = new Scope();
        $scope->onCancel(static function () use (&$calls) {
            ++$calls;
        });

        $scope->cancel(CancelCause::Handle, by: null);
        $before = $calls;
        $scope->close();
        $afterClose = $calls;
        checkpoint();

        Assert::same([$before, $afterClose, $calls], [0, 1, 1]);
    }

    public function failureOfCallbackCalledByCloseGoesToEventLoop(): void
    {
        $second = false;
        $scope = new Scope();
        $scope->onCancel(static function () {
            throw new \RuntimeException('callback failed');
        });
        $scope->onCancel(static function () use (&$second) {
            $second = true;
        });
        // The loop throws it into the wait of the fiber it runs: the one of the test.
        $exception = thrown(static function () use ($scope) {
            $scope->cancel(CancelCause::Handle, by: null);
            $scope->close();
            checkpoint();
        });

        Assert::true($second);
        Assert::same($exception->getMessage(), 'callback failed');
    }

    public function callbacksDoNotAccumulateInLongLivedScope(): void
    {
        $scope = new Scope();

        for ($i = 0; $i < 3; ++$i) {
            $scope->onCancel(static function () {})();
        }
        $scope->onCancel(static function () {});
        $scope->cancel(CancelCause::Handle, by: null);
        checkpoint();

        Assert::same((fn() => $this->callbacks)->call($scope), []);
    }

    public function remainingFitsDurationWhenClockIsNearOrigin(): void
    {
        // A deadline saturated at PHP_INT_MAX is further than the longest Duration while the clock is below 0.86 s.
        $clock = Scope::$virtualClock;
        Scope::$virtualClock = new VirtualClock(0);

        try {
            $scope = new Scope(timeout: PHP_INT_MAX);
            $remaining = $scope->remaining();
            $scope->close();
        } finally {
            Scope::$virtualClock = $clock;
        }

        Assert::same($remaining, 9_223_372_035_999_999_999);
    }
}
