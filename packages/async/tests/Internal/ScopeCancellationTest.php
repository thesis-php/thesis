<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Amp\CancelledException;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\checkpoint;

#[Test]
final class ScopeCancellationTest
{
    public function isRequestedExactlyWhileScopeIsCancelled(): void
    {
        $scope = new Scope();
        $cancellation = new ScopeCancellation($scope);

        $before = $cancellation->isRequested();
        $cancellation->throwIfRequested();
        $scope->cancel();

        Assert::false($before);
        Assert::true($cancellation->isRequested());
    }

    public function throwIfRequestedThrowsCancelledException(): void
    {
        $scope = new Scope();
        $scope->cancel();

        Expect::exception(CancelledException::class);

        new ScopeCancellation($scope)->throwIfRequested();
    }

    public function subscriberReceivesCancelledExceptionOnce(): void
    {
        $scope = new Scope();
        $cancellation = new ScopeCancellation($scope);
        $received = [];
        $cancellation->subscribe(static function (CancelledException $exception) use (&$received) {
            $received[] = $exception;
        });

        $scope->cancel();
        $scope->cancel();
        checkpoint();

        Assert::count($received, 1);
    }

    public function unsubscribedCallbackIsNotCalledEvenIfAlreadyQueued(): void
    {
        $scope = new Scope();
        $cancellation = new ScopeCancellation($scope);
        $calls = [];
        $first = $cancellation->subscribe(static function () use (&$calls) {
            $calls[] = 'first';
        });
        $cancellation->subscribe(static function () use (&$calls) {
            $calls[] = 'second';
        });

        $scope->cancel();
        $cancellation->unsubscribe($first);
        $cancellation->unsubscribe($first);
        $cancellation->unsubscribe('unknown');
        checkpoint();

        Assert::same($calls, ['second']);
    }

    public function subscriptionIdsAreDistinct(): void
    {
        $cancellation = new ScopeCancellation(new Scope());

        Assert::notSame(
            $cancellation->subscribe(static function () {}),
            $cancellation->subscribe(static function () {}),
        );
    }

    public function staleIdDoesNotUnsubscribeLaterSubscription(): void
    {
        $scope = new Scope();
        $cancellation = new ScopeCancellation($scope);
        $calls = 0;
        $stale = $cancellation->subscribe(static function () {});
        $cancellation->unsubscribe($stale);
        $cancellation->subscribe(static function () use (&$calls) {
            ++$calls;
        });

        $cancellation->unsubscribe($stale);
        $scope->cancel();
        checkpoint();

        Assert::same($calls, 1);
    }
}
