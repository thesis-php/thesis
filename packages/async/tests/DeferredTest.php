<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Time\sec;

#[Test]
final class DeferredTest
{
    public function awaitReturnsValueCompletedLater(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();
        EventLoop::delay(1, static function () use ($deferred) {
            $deferred->complete('value');
        });

        Assert::same($deferred->await(), 'value');
    }

    public function completeWithoutValueResolvesToNull(): void
    {
        /** @var Deferred<null> */
        $deferred = new Deferred();
        $deferred->complete();

        Assert::true($deferred->isDone);
        Assert::null($deferred->await());
    }

    public function isDoneOnlyAfterSettled(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();
        Assert::false($deferred->isDone);

        $deferred->complete('value');

        Assert::true($deferred->isDone);
    }

    public function awaitRethrowsFailure(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();
        $failure = new \RuntimeException('failed');

        $exceptions = group(static function (Group $group) use ($deferred, $failure) {
            $awaiters = [
                $group->spawn(static fn() => thrown($deferred->await(...))),
                $group->spawn(static fn() => thrown($deferred->await(...))),
            ];
            delay(sec(1));

            $deferred->fail($failure);

            return [$awaiters[0]->await(), $awaiters[1]->await(), thrown($deferred->await(...))];
        });

        Assert::same($exceptions, [$failure, $failure, $failure]);
        Assert::true($deferred->isDone);
    }

    public function wakesEveryAwaiter(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();

        $results = group(static function (Group $group) use ($deferred) {
            $awaiters = [];

            for ($i = 0; $i < 3; ++$i) {
                $awaiters[] = $group->spawn($deferred->await(...));
            }

            delay(sec(1));
            $deferred->complete('value');

            return array_map(static fn(Task $awaiter) => $awaiter->await(), $awaiters);
        });

        Assert::same($results, ['value', 'value', 'value']);
    }

    public function firstSettlementWins(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();

        $results = [
            $deferred->complete('first'),
            $deferred->complete('second'),
            $deferred->fail(new \RuntimeException()),
        ];

        Assert::same($results, [true, false, false]);
        Assert::same($deferred->await(), 'first');
    }

    public function firstFailureWins(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();
        $failure = new \RuntimeException('first');

        $results = [
            $deferred->fail($failure),
            $deferred->fail(new \LogicException('second')),
            $deferred->complete('value'),
        ];

        Assert::same($results, [true, false, false]);
        Assert::same(thrown($deferred->await(...)), $failure);
    }

    public function failWithCancelledIsRejected(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();
        $cancelled = thrown(static fn() => timeout(sec(1), never(...)))->getPrevious();
        Assert::instanceOf($cancelled, Cancelled::class);

        $exception = thrown(static fn() => $deferred->fail($cancelled));

        Assert::instanceOf($exception, \ValueError::class);
        Assert::same(
            actual: $exception->getMessage(),
            expected: 'A deferred cannot fail with Cancelled: its awaiters are not cancelled. Fail it with a regular exception.',
        );
        Assert::false($deferred->isDone);
    }

    public function awaitIsCancellable(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();

        $exception = thrown(static fn() => timeout(sec(1), $deferred->await(...)));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::false($deferred->isDone);
    }

    public function cancelledAwaiterDoesNotAffectOthers(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();

        $result = group(static function (Group $group) use ($deferred) {
            $cancelled = $group->spawn($deferred->await(...));
            $other = $group->spawn($deferred->await(...));
            delay(sec(1));
            $cancelled->cancel();
            delay(sec(1));

            $deferred->complete('value');

            return $other->await();
        });

        Assert::same($result, 'value');
    }

    public function valueDeliveredBeforeCancellationIsReturned(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();

        $result = group(static function (Group $group) use ($deferred) {
            $awaiter = $group->spawn($deferred->await(...));
            delay(sec(1));

            $deferred->complete('value');
            $awaiter->cancel();

            return $awaiter->await();
        });

        Assert::same($result, 'value');
    }

    public function cancelledAwaiterDoesNotStayRegistered(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();

        for ($i = 0; $i < 3; ++$i) {
            thrown(static fn() => timeout(sec(1), $deferred->await(...)));
        }

        Assert::same((fn() => $this->waiters)->call($deferred), []);
    }

    public function awaitOfDoneDeferredReturnsValueEveryTimeWithoutWaiting(): void
    {
        /** @var Deferred<string> */
        $deferred = new Deferred();
        $deferred->complete('value');

        $results = timeout(sec(1), static function () use ($deferred) {
            untilCancelled();

            return [$deferred->await(), $deferred->await()];
        });

        Assert::same($results, ['value', 'value']);
    }

    public function awaitInCancelledScopeOfPendingDeferredThrowsCancelled(): void
    {
        Expect::exception(TimedOut::class);

        /** @var Deferred<string> */
        $deferred = new Deferred();

        timeout(sec(1), static function () use ($deferred) {
            untilCancelled();

            $deferred->await();
        });
    }
}
