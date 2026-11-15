<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Test;
use Thesis\Async\Cancelled;
use function Thesis\Async\checkpoint;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\timeout;

#[Test]
final class ScopeTest
{
    public function rootIsTheSameOutsideAnyScope(): void
    {
        $root = Scope::current();

        timeout(1000, static fn() => delay(1));
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
            timeout(1000, static fn() => null);
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

        $scope->cancel();

        Assert::same(
            actual: array_map(
                callback: static fn(Scope $scope) => $scope->isCancelled(),
                array: [$scope, $child, $grandchild, $shield, $shielded],
            ),
            expected: [true, true, true, false, false],
        );
    }

    public function scopeCreatedInCancelledScopeIsCancelledUnlessShield(): void
    {
        $scope = new Scope();
        $scope->cancel();
        $child = new Scope($scope);
        $grandchild = new Scope($child);
        $shield = new Scope($scope, shield: true);
        $shielded = new Scope($shield);

        Assert::same(
            actual: array_map(
                callback: static fn(Scope $scope) => $scope->isCancelled(),
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
        $grandchild->cancel();
        /** @var ContinuationImpl<null> */
        $wait = new ContinuationImpl();
        $child->subscribe($wait);

        $scope->cancel();

        Assert::true($child->isCancelled());
        Assert::false($wait->resume());
    }

    public function subscribedWaitIsInterruptedOnCancellation(): void
    {
        $scope = new Scope();
        $child = new Scope($scope);
        /** @var ContinuationImpl<string> */
        $wait = new ContinuationImpl();
        $child->subscribe($wait);

        $scope->cancel();

        Assert::false($wait->resume('too late'));
        Assert::instanceOf(thrown($wait->park(...)), Cancelled::class);
    }

    public function subscriptionToCancelledScopeIsInterruptedRightAway(): void
    {
        $scope = new Scope();
        $scope->cancel();
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

        $scope->cancel();

        Assert::true($wait->resume('value'));
        Assert::same($wait->park(), 'value');
    }

    public function throwIfCancelledThrowsOnlyInCancelledScope(): void
    {
        $scope = new Scope();
        $scope->throwIfCancelled();

        $scope->cancel();

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
        $fiber = new \Fiber(static fn() => \Thesis\Async\Tests\never());
        $fiber->start();
        $waits = (fn(): array => $this->waits)->call(ROOT_SCOPE);

        Assert::same($waits, []);
    }

    public function closedScopeStoresNoCallbacks(): void
    {
        $called = false;
        $scope = new Scope();
        $scope->close();

        $scope->onCancel(static function () use (&$called) {
            $called = true;
        });
        $callbacks = (fn(): array => $this->callbacks)->call($scope);
        $scope->cancel();
        checkpoint();

        Assert::same($callbacks, []);
        Assert::false($called);
    }

    public function closedCancelledScopeQueuesNoCallbacks(): void
    {
        $called = false;
        $scope = new Scope();
        $scope->cancel();
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

        $scope->cancel();
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
        $errors = [];
        $previous = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (\Throwable $error) use (&$errors) {
            $errors[] = $error->getMessage();
        });

        try {
            $scope->cancel();
            $scope->close();
            checkpoint();
        } finally {
            EventLoop::setErrorHandler($previous);
        }

        Assert::true($second);
        Assert::same($errors, ['callback failed']);
    }

    public function callbacksDoNotAccumulateInLongLivedScope(): void
    {
        $scope = new Scope();

        for ($i = 0; $i < 3; ++$i) {
            $scope->onCancel(static function () {})();
        }
        $scope->onCancel(static function () {});
        $scope->cancel();
        checkpoint();

        Assert::same((fn(): array => $this->callbacks)->call($scope), []);
    }
}
