<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Cancelled;
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

        timeout(1, static fn() => delay(0.001));
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
            timeout(1, static fn() => null);
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
            array_map(static fn(Scope $scope) => $scope->isCancelled(), [$scope, $child, $grandchild, $shield, $shielded]),
            [true, true, true, false, false],
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
            array_map(static fn(Scope $scope) => $scope->isCancelled(), [$child, $grandchild, $shield, $shielded]),
            [true, true, false, false],
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
}
