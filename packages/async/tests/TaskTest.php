<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\busy;
use function Thesis\Async\Tests\thrown;

#[Test]
final class TaskTest
{
    public function awaitReturnsResult(): void
    {
        $result = group(static function (Group $group) {
            $task = $group->spawn(static function () {
                delay(0.001);

                return 'result';
            });

            return $task->await();
        });

        Assert::same($result, 'result');
    }

    public function awaitOfFinishedTaskReturnsResult(): void
    {
        $result = group(static function (Group $group) {
            $task = $group->spawn(static fn() => 'result');
            delay(0.001);
            Assert::true($task->isDone);

            return [$task->await(), $task->await()];
        });

        Assert::same($result, ['result', 'result']);
    }

    public function isDoneOnlyAfterTaskFinished(): void
    {
        group(static function (Group $group) {
            $task = $group->spawn(static fn() => delay(0.001));
            Assert::false($task->isDone);

            $task->await();

            Assert::true($task->isDone);
        });
    }

    public function awaitRethrowsTaskFailure(): void
    {
        $failure = new \RuntimeException('task failed');
        $awaited = null;

        $exception = thrown(static function () use ($failure, &$awaited) {
            group(static function (Group $group) use ($failure, &$awaited) {
                $task = $group->spawn(static function () use ($failure) {
                    delay(0.001);

                    throw $failure;
                });

                $awaited = thrown(static fn() => $task->await());
            });
        });

        Assert::same($awaited, $failure);
        Assert::same($exception, $failure);
    }

    public function awaitOfCancelledTaskThrowsTaskCancelled(): void
    {
        $exception = group(static function (Group $group) {
            $task = $group->spawn(static fn() => delay(1));
            delay(0.001);
            $task->cancel();

            return thrown(static fn() => $task->await());
        });

        Assert::instanceOf($exception, TaskCancelled::class);
        Assert::same($exception->getMessage(), 'The task was cancelled.');
        Assert::instanceOf($exception->getPrevious(), Cancelled::class);
    }

    public function awaitOfTaskCancelledWhileAwaitedThrowsTaskCancelled(): void
    {
        $exception = group(static function (Group $group) {
            $task = $group->spawn(static fn() => delay(1));
            $group->spawn(static function () use ($task) {
                delay(0.001);
                $task->cancel();
            });

            return thrown(static fn() => $task->await());
        });

        Assert::instanceOf($exception, TaskCancelled::class);
    }

    public function uncaughtTaskCancelledFailsGroup(): void
    {
        Expect::exception(TaskCancelled::class);

        group(static function (Group $group) {
            $task = $group->spawn(static fn() => delay(1));
            $task->cancel();
            $task->await();
        });
    }

    public function awaitInCancelledScopeThrowsCancelledEvenIfTaskWasCancelled(): void
    {
        $awaited = null;

        thrown(static function () use (&$awaited) {
            timeout(0.001, static function () use (&$awaited) {
                group(static function (Group $group) use (&$awaited) {
                    $task = $group->spawn(static fn() => delay(1));
                    busy(0.005);

                    try {
                        delay(1); // the deadline cancels both the task and the body
                    } catch (Cancelled) {
                    }

                    shield(static fn() => delay(0.002)); // let the task finish as cancelled
                    Assert::true($task->isDone);

                    $awaited = thrown(static fn() => $task->await());
                });
            });
        });

        Assert::instanceOf($awaited, Cancelled::class);
    }

    public function awaitIsCancellable(): void
    {
        $taskFinished = false;

        group(static function (Group $group) use (&$taskFinished) {
            $task = $group->spawn(static function () use (&$taskFinished) {
                delay(0.02);
                $taskFinished = true;
            });

            $exception = thrown(static fn() => timeout(0.001, static fn() => $task->await()));

            Assert::instanceOf($exception, TimedOut::class);
            Assert::false($task->isDone);
        });

        Assert::true($taskFinished);
    }

    public function wakesEveryAwaiter(): void
    {
        $results = group(static function (Group $group) {
            $task = $group->spawn(static function () {
                delay(0.002);

                return 'result';
            });
            $awaiters = [];

            for ($i = 0; $i < 3; ++$i) {
                $awaiters[] = $group->spawn(static fn() => $task->await());
            }

            return array_map(static fn(Task $awaiter) => $awaiter->await(), $awaiters);
        });

        Assert::same($results, ['result', 'result', 'result']);
    }

    public function cancelAffectsOnlyThisTask(): void
    {
        $results = group(static function (Group $group) {
            $cancelled = $group->spawn(static fn() => delay(1));
            $sibling = $group->spawn(static function () {
                delay(0.002);

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
            $task = $group->spawn(static fn() => 'result');
            delay(0.001);
            $task->cancel();

            return $task->await();
        });

        Assert::same($result, 'result');
    }
}
