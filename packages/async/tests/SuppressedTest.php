<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Internal\Suppressed;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Time\sec;
use const Thesis\Time\ZERO;

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

        $exception = thrown(static fn() => self::failOneAfterAnother(new \RuntimeException('first'), $second, $third));

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
                    delay(sec(1));

                    throw $first;
                });
                $group->spawn(static function () use (&$rollback) {
                    try {
                        never();
                    } catch (\Throwable $exception) {
                        shield(static fn() => delay(sec(1)));

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
                $handle = $group->spawn(static fn() => throw new \RuntimeException('failed'));

                // The task's error is delivered before the body is cancelled: the same object comes back.
                $handle->await();
            });
        });

        Assert::same(suppressed($exception), []);
    }

    public function sameSuppressedErrorIsRecordedOnce(): void
    {
        $second = new \LogicException('second');

        $exception = thrown(static function () use ($second) {
            group(static function (Group $group) use ($second) {
                $group->spawn(static function () {
                    delay(sec(1));

                    throw new \RuntimeException('first');
                });
                $failing = $group->spawn(static function () use ($second) {
                    shield(static fn() => delay(sec(2)));

                    throw $second;
                });
                $group->spawn(static fn() => shield($failing->await(...))); // rethrows $second
            });
        });

        Assert::same($exception->getMessage(), 'first');
        Assert::same(suppressed($exception), [$second]);
    }

    public function errorsAreNeverSuppressedUnderEachOther(): void
    {
        /** @var Deferred<null> */
        $shared = new Deferred();
        $outerError = new \RuntimeException('outer');
        /** @var ?\RuntimeException $innerError */
        $innerError = null;

        $exception = thrown(static function () use ($shared, $outerError, &$innerError) {
            group(static function (Group $group) use ($shared, $outerError, &$innerError) {
                $group->spawn(static fn() => $shared->await()); // fails the outer group first
                $group->spawn(static function () use ($shared, &$innerError) {
                    group(static function (Group $innerGroup) use ($shared, &$innerError) {
                        $innerGroup->spawn(static function () use (&$innerError) {
                            delay(sec(1));

                            throw $innerError = new \RuntimeException('inner');
                        });
                        $innerGroup->spawn(static function () use ($shared) {
                            try {
                                never();
                            } finally {
                                shield(static fn() => $shared->await()); // suppressed under the inner error
                            }
                        });
                    });
                });

                delay(sec(2));
                $shared->fail($outerError);
            });
        });

        Assert::same($exception, $outerError);
        Assert::instanceOf($innerError, \RuntimeException::class);
        Assert::same(suppressed($outerError), [$innerError]);
        // the error rethrown keeps every other one in its tree, without a cycle back to itself
        Assert::same(suppressed($innerError), []);
    }

    public function errorIsNeverSuppressedUnderItsOwnDescendant(): void
    {
        $first = new \RuntimeException('first');
        $second = new \RuntimeException('second');
        $third = new \RuntimeException('third');
        Suppressed::add($second, $third);
        Suppressed::add($third, $first);

        Suppressed::add($first, $second);

        Assert::same(suppressed($first), [$second]);
        Assert::same(suppressed($second), [$third]);
        Assert::same(suppressed($third), []);
    }

    public function errorIsNeverSuppressedUnderDescendantBehindLeaf(): void
    {
        $first = new \RuntimeException('first');
        $second = new \RuntimeException('second');
        $deep = new \RuntimeException('deep');
        $leaf = new \RuntimeException('leaf');
        Suppressed::add($second, $deep);
        Suppressed::add($second, $leaf);
        Suppressed::add($deep, $first);

        Suppressed::add($first, $second);

        Assert::same(suppressed($first), [$second]);
        Assert::same(suppressed($deep), []);
    }

    public function nestedGroupsGiveTree(): void
    {
        $innerSecond = null;

        $exception = thrown(static function () use (&$innerSecond) {
            group(static function (Group $group) use (&$innerSecond) {
                $group->spawn(static function () {
                    delay(sec(1));

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
                                // After the first: the tasks cancelled at once may unwind in any order.
                                shield(static fn() => delay(sec(1)));

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
                    delay(sec(1));

                    throw $failure;
                });
            });
        });

        Assert::instanceOf($exception, Deadlock::class);
        Assert::same(suppressed($exception), [$failure]);

        // let the orphaned task finish
        $never->complete();
        delay(ZERO);
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

        Assert::instanceOf($exception, Deadlock::class);
        Assert::same(suppressed($exception), []);

        $never->complete();
        delay(ZERO);
    }

    public function entryGoesAwayWithError(): void
    {
        // Never thrown: Xdebug's develop mode keeps the last thrown exceptions, and the entry would stay with them.
        $first = new \RuntimeException('first');
        $second = new \RuntimeException('second');
        Suppressed::add($first, $second);
        $reference = \WeakReference::create($second);
        unset($second);

        Assert::same(suppressed($first), [$reference->get()]);

        unset($first);

        Assert::null($reference->get());
    }

    /** The tasks are woken in the same tick, before any has failed: a later failure is not caused by the first. */
    /**
     * Each error a second after the one before, the later ones while the group is already failing: failures
     * in one tick may come in any order.
     */
    private static function failOneAfterAnother(\Throwable ...$errors): void
    {
        group(static function (Group $group) use ($errors) {
            foreach (array_values($errors) as $i => $error) {
                $group->spawn(static function () use ($i, $error) {
                    shield(static fn() => delay(sec($i + 1)));

                    throw $error;
                });
            }
        });
    }
}
