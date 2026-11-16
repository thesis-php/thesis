<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Amp\CancelledException;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Trace\CancelCause;
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
        $scope->cancel(CancelCause::Handle, by: null);

        Assert::false($before);
        Assert::true($cancellation->isRequested());
    }

    public function throwIfRequestedThrowsCancelledException(): void
    {
        $scope = new Scope();
        $scope->cancel(CancelCause::Handle, by: null);

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

        $scope->cancel(CancelCause::Handle, by: null);
        $scope->cancel(CancelCause::Handle, by: null);
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

        $scope->cancel(CancelCause::Handle, by: null);
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
            actual: $cancellation->subscribe(static function () {}),
            expected: $cancellation->subscribe(static function () {}),
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
        $scope->cancel(CancelCause::Handle, by: null);
        checkpoint();

        Assert::same($calls, 1);
    }
}
