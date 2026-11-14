<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use const Thesis\Async\Internal\SUPPRESSED;

#[Test]
final class SuppressedTest
{
    public function nothingIsSuppressedForSingleFailure(): void
    {
        $exception = thrown(static function () {
            group(static function (Group $group) {
                $group->spawn(static fn() => throw new \RuntimeException('failed'));
            });
        });

        Assert::same(suppressed($exception), []);
    }

    public function nothingIsSuppressedForErrorNotFromGroup(): void
    {
        Assert::same(suppressed(new \RuntimeException()), []);
    }

    public function simultaneousFailuresAreSuppressedInOrder(): void
    {
        $second = new \LogicException('second');
        $third = new \LogicException('third');

        $exception = thrown(static fn() => failAtOnce(new \RuntimeException('first'), $second, $third));

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($exception->getMessage(), 'first');
        Assert::null($exception->getPrevious());
        Assert::same(suppressed($exception), [$second, $third]);
    }

    public function rollbackFailureOnCancellationIsSuppressed(): void
    {
        $first = new \RuntimeException('first');
        $rollback = null;

        $exception = thrown(static function () use ($first, &$rollback) {
            group(static function (Group $group) use ($first, &$rollback) {
                $group->spawn(static function () use ($first) {
                    delay(1);

                    throw $first;
                });
                $group->spawn(static function () use (&$rollback) {
                    try {
                        never();
                    } catch (\Throwable $exception) {
                        shield(static fn() => delay(1));

                        throw $rollback = new \LogicException('rollback failed', previous: $exception);
                    }
                });
            });
        });

        Assert::same($exception, $first);
        Assert::same(suppressed($exception), [$rollback]);
    }

    public function bodyFailureAfterTaskFailureIsSuppressed(): void
    {
        $body = null;

        $exception = thrown(static function () use (&$body) {
            group(static function (Group $group) use (&$body) {
                $group->spawn(static fn() => throw new \RuntimeException('task failed'));

                try {
                    never();
                } catch (Cancelled) {
                    throw $body = new \LogicException('body failed');
                }
            });
        });

        Assert::same($exception->getMessage(), 'task failed');
        Assert::same(suppressed($exception), [$body]);
    }

    public function sameErrorRethrownByBodyIsNotSuppressed(): void
    {
        $exception = thrown(static function () {
            group(static function (Group $group) {
                $task = $group->spawn(static fn() => throw new \RuntimeException('failed'));

                $task->await(); // the task's error is delivered before the body is cancelled: the same object comes back
            });
        });

        Assert::same(suppressed($exception), []);
    }

    public function sameSuppressedErrorIsRecordedOnce(): void
    {
        $second = new \LogicException('second');

        $exception = thrown(static function () use ($second) {
            group(static function (Group $group) use ($second) {
                /** @var Deferred<null> */
                $go = new Deferred();
                $group->spawn(static function () use ($go) {
                    $go->await();

                    throw new \RuntimeException('first');
                });
                $failing = $group->spawn(static function () use ($go, $second) {
                    $go->await();

                    throw $second;
                });
                $group->spawn(static fn() => $failing->await()); // rethrows $second

                delay(1);
                $go->complete(null);
            });
        });

        Assert::same($exception->getMessage(), 'first');
        Assert::same(suppressed($exception), [$second]);
    }

    public function nestedGroupsGiveTree(): void
    {
        $innerSecond = null;

        $exception = thrown(static function () use (&$innerSecond) {
            group(static function (Group $group) use (&$innerSecond) {
                $group->spawn(static function () {
                    delay(1);

                    throw new \RuntimeException('outer first');
                });
                $group->spawn(static function () use (&$innerSecond) {
                    group(static function (Group $group) use (&$innerSecond) {
                        $group->spawn(never(...));
                        $group->spawn(static function () {
                            try {
                                never();
                            } catch (Cancelled) {
                                throw new \LogicException('inner first');
                            }
                        });
                        $group->spawn(static function () use (&$innerSecond) {
                            try {
                                never();
                            } catch (Cancelled) {
                                throw $innerSecond = new \LogicException('inner second');
                            }
                        });
                    });
                });
            });
        });

        Assert::same($exception->getMessage(), 'outer first');
        [$inner] = suppressed($exception) + [null];
        Assert::count(suppressed($exception), 1);
        Assert::instanceOf($inner, \LogicException::class);
        Assert::same($inner->getMessage(), 'inner first');
        Assert::same(suppressed($inner), [$innerSecond]);
    }

    public function failureIsSuppressedByDeadlockThatReplacesIt(): void
    {
        /** @var Deferred<null> */
        $never = new Deferred();
        $failure = new \RuntimeException('failed');

        $exception = thrown(static function () use ($never, $failure) {
            group(static function (Group $group) use ($never, $failure) {
                $group->spawn(static fn() => shield($never->await(...)));
                $group->spawn(static function () use ($failure) {
                    delay(1);

                    throw $failure;
                });
            });
        });

        Assert::string($exception->getMessage())->startsWith('Event loop terminated without resuming the current suspension');
        Assert::same(suppressed($exception), [$failure]);

        // let the orphaned task finish
        $never->complete();
        delay(0);
    }

    public function deadlockIsNotSuppressedBySameDeadlock(): void
    {
        /** @var Deferred<null> */
        $never = new Deferred();

        $exception = thrown(static function () use ($never) {
            group(static function (Group $group) use ($never) {
                $group->spawn(static fn() => shield($never->await(...)));
            });
        });

        Assert::string($exception->getMessage())->startsWith('Event loop terminated without resuming the current suspension');
        Assert::same(suppressed($exception), []);

        $never->complete();
        delay(0);
    }

    public function entryGoesAwayWithError(): void
    {
        $count = \count(SUPPRESSED);

        $exception = thrown(static fn() => failAtOnce(new \RuntimeException('first'), new \RuntimeException('second')));

        Assert::same(\count(SUPPRESSED), $count + 1);

        unset($exception);

        Assert::same(\count(SUPPRESSED), $count);
    }
}

/**
 * The tasks are woken in the same tick, before any has failed: a later failure is not caused by the first.
 */
function failAtOnce(\Throwable ...$errors): void
{
    group(static function (Group $group) use ($errors) {
        /** @var Deferred<null> */
        $go = new Deferred();

        foreach ($errors as $error) {
            $group->spawn(static function () use ($go, $error) {
                $go->await();

                throw $error;
            });
        }

        delay(1);
        $go->complete(null);
    });
}
