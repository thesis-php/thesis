<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Async\VirtualTime\advance;
use function Thesis\Time\sec;

#[Test]
final class TaskTest
{
    public function awaitReturnsResult(): void
    {
        $result = group(static function (Group $group) {
            $handle = $group->spawn(static function () {
                delay(sec(1));

                return 'result';
            });

            return $handle->await();
        });

        Assert::same($result, 'result');
    }

    public function awaitOfFinishedTaskReturnsResult(): void
    {
        $result = group(static function (Group $group) {
            $handle = $group->spawn(static fn() => 'result');
            delay(sec(1));
            Assert::true($handle->isDone);

            return [$handle->await(), $handle->await()];
        });

        Assert::same($result, ['result', 'result']);
    }

    public function isDoneOnlyAfterTaskFinished(): void
    {
        group(static function (Group $group) {
            $handle = $group->spawn(static fn() => delay(sec(1)));
            Assert::false($handle->isDone);

            $handle->await();

            Assert::true($handle->isDone);
        });
    }

    public function awaitRethrowsTaskFailure(): void
    {
        $failure = new \RuntimeException('task failed');
        $awaited = null;

        $exception = thrown(static function () use ($failure, &$awaited) {
            group(static function (Group $group) use ($failure, &$awaited) {
                $handle = $group->spawn(static function () use ($failure) {
                    delay(sec(1));

                    throw $failure;
                });

                $awaited = thrown(static fn() => $handle->await());
            });
        });

        Assert::same($awaited, $failure);
        Assert::same($exception, $failure);
    }

    public function awaitOfCancelledTaskThrowsTaskCancelled(): void
    {
        $exception = group(static function (Group $group) {
            $handle = $group->spawn(never(...));
            delay(sec(1));
            $handle->cancel();

            return thrown(static fn() => $handle->await());
        });

        Assert::instanceOf($exception, TaskCancelled::class);
        Assert::same($exception->getMessage(), 'The task was cancelled.');
        Assert::instanceOf($exception->getPrevious(), Cancelled::class);
    }

    public function awaitOfTaskCancelledWhileAwaitedThrowsTaskCancelled(): void
    {
        $exception = group(static function (Group $group) {
            $handle = $group->spawn(never(...));
            $group->spawn(static function () use ($handle) {
                delay(sec(1));
                $handle->cancel();
            });

            return thrown(static fn() => $handle->await());
        });

        Assert::instanceOf($exception, TaskCancelled::class);
    }

    public function uncaughtTaskCancelledFailsGroup(): void
    {
        Expect::exception(TaskCancelled::class);

        group(static function (Group $group) {
            $handle = $group->spawn(never(...));
            $handle->cancel();
            $handle->await();
        });
    }

    public function awaitOfFinishedTaskInCancelledScopeReturnsOutcomeAsIs(): void
    {
        $failure = new \RuntimeException('failure');
        $succeeded = null;
        $failed = null;
        group(static function (Group $group) use (&$succeeded) {
            $succeeded = $group->spawn(static fn() => 'result');
        });
        thrown(static function () use ($failure, &$failed) {
            group(static function (Group $group) use ($failure, &$failed) {
                $failed = $group->spawn(static fn() => throw $failure);
            });
        });
        \assert($succeeded instanceof Task && $failed instanceof Task);
        $outcomes = null;

        timeout(sec(1), static function () use ($succeeded, $failed, &$outcomes) {
            try {
                never();
            } catch (Cancelled) {
                // Not a cancellation point: the outcome of a finished task is returned or thrown as is.
                $outcomes = [$succeeded->await(), thrown(static fn() => $failed->await())];
            }
        });

        Assert::same($outcomes, ['result', $failure]);
    }

    public function awaitOfFinishedCancelledTaskInCancelledScopeThrowsCancelled(): void
    {
        $awaited = null;

        thrown(static function () use (&$awaited) {
            timeout(sec(1), static function () use (&$awaited) {
                group(static function (Group $group) use (&$awaited) {
                    $handle = $group->spawn(never(...));
                    advance(sec(5));

                    untilCancelled(); // the deadline cancels both the task and the body

                    shield(static fn() => delay(sec(2))); // let the task finish as cancelled
                    Assert::true($handle->isDone);

                    // The awaiter is cancelled too, so it ends as cancelled.
                    $awaited = thrown(static fn() => $handle->await());
                });
            });
        });

        Assert::instanceOf($awaited, Cancelled::class);
    }

    public function timeoutAroundTaskAwaitingFinishedCancelledSiblingThrowsTimedOut(): void
    {
        Expect::exception(TimedOut::class);

        timeout(sec(1), static function () {
            group(static function (Group $group) {
                $sibling = $group->spawn(never(...));
                $group->spawn(static function () use ($sibling) {
                    shield(static fn() => delay(sec(2))); // the deadline cancels the sibling meanwhile
                    Assert::true($sibling->isDone);
                    $sibling->await();
                });
            });
        });
    }

    public function awaiterCancelledAfterCancelledTaskWokeItThrowsCancelled(): void
    {
        $caught = null;

        group(static function (Group $group) use (&$caught) {
            $handle = $group->spawn(never(...));
            /** @var Deferred<Task<never>> */
            $second = new Deferred();
            // Woken first, it cancels the second awaiter, which has already been woken and not run yet.
            $group->spawn(static function () use ($handle, $second) {
                thrown(static fn() => $handle->await());
                $second->await()->cancel();
            });
            $second->complete($group->spawn(static function () use ($handle, &$caught) {
                $caught = thrown(static fn() => $handle->await());

                throw $caught;
            }));
            delay(sec(1));
            $handle->cancel();
        });

        Assert::instanceOf($caught, Cancelled::class);
    }

    public function awaitIsCancellable(): void
    {
        $taskFinished = false;

        group(static function (Group $group) use (&$taskFinished) {
            $handle = $group->spawn(static function () use (&$taskFinished) {
                delay(sec(20));
                $taskFinished = true;
            });

            $exception = thrown(static fn() => timeout(sec(1), static fn() => $handle->await()));

            Assert::instanceOf($exception, TimedOut::class);
            Assert::false($handle->isDone);
        });

        Assert::true($taskFinished);
    }

    public function wakesEveryAwaiter(): void
    {
        $results = group(static function (Group $group) {
            $handle = $group->spawn(static function () {
                delay(sec(2));

                return 'result';
            });
            $awaiters = [];

            for ($i = 0; $i < 3; ++$i) {
                $awaiters[] = $group->spawn(static fn() => $handle->await());
            }

            return array_map(static fn(Task $awaiter) => $awaiter->await(), $awaiters);
        });

        Assert::same($results, ['result', 'result', 'result']);
    }

    public function cancelAffectsOnlyThisTask(): void
    {
        $results = group(static function (Group $group) {
            $cancelled = $group->spawn(never(...));
            $sibling = $group->spawn(static function () {
                delay(sec(2));

                return 'sibling';
            });
            $cancelled->cancel();

            return $sibling->await();
        });

        Assert::same($results, 'sibling');
    }

    public function cancellingFinishedTaskIsNoOp(): void
    {
        $result = group(static function (Group $group) {
            $handle = $group->spawn(static fn() => 'result');
            delay(sec(1));
            $handle->cancel();

            return $handle->await();
        });

        Assert::same($result, 'result');
    }
}
