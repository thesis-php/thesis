<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;

#[Test]
final class OnCancelTest
{
    public function isCalledOnceWhenScopeIsCancelled(): void
    {
        $calls = 0;

        $exception = thrown(static function () use (&$calls) {
            timeout(1, static function () use (&$calls) {
                onCancel(static function () use (&$calls) {
                    ++$calls;
                });

                never();
            });
        });

        checkpoint(); // the callback is queued, and {main} is resumed before the queue is drained

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same($calls, 1);
        assertNoCallbacksLeft();
    }

    public function isCalledWhenAncestorIsCancelled(): void
    {
        $calls = 0;

        thrown(static function () use (&$calls) {
            timeout(1, static function () use (&$calls) {
                group(static function (Group $group) use (&$calls) {
                    $group->spawn(static function () use (&$calls) {
                        timeout(10, static function () use (&$calls) {
                            onCancel(static function () use (&$calls) {
                                ++$calls;
                            });

                            never();
                        });
                    });
                });
            });
        });
        checkpoint();

        Assert::same($calls, 1);
    }

    public function isCalledOnceWhenCancelledTwice(): void
    {
        $calls = 0;

        thrown(static function () use (&$calls) {
            timeout(5, static function () use (&$calls) {
                group(static function (Group $group) use (&$calls) {
                    $task = $group->spawn(static function () use (&$calls) {
                        onCancel(static function () use (&$calls) {
                            ++$calls;
                        });

                        try {
                            never();
                        } finally {
                            shield(static fn() => delay(10));
                        }
                    });

                    delay(1);
                    $task->cancel();
                    $task->cancel();
                    never();
                });
            });
        });

        Assert::same($calls, 1);
    }

    public function isNotCalledSynchronouslyOnCancellation(): void
    {
        $observed = null;

        group(static function (Group $group) use (&$observed) {
            $called = false;
            $task = $group->spawn(static function () use (&$called) {
                onCancel(static function () use (&$called) {
                    $called = true;
                });

                never();
            });

            delay(1);
            $task->cancel();
            $observed = $called;
        });

        Assert::false($observed);
    }

    public function isNotCalledAfterUnsubscribe(): void
    {
        $called = false;

        thrown(static function () use (&$called) {
            timeout(1, static function () use (&$called) {
                $unsubscribe = onCancel(static function () use (&$called) {
                    $called = true;
                });
                $unsubscribe();
                $unsubscribe();

                never();
            });
        });

        checkpoint();

        Assert::false($called);
    }

    public function isNotCalledIfUnsubscribedAfterCancellationBeforeItRuns(): void
    {
        $called = false;

        group(static function (Group $group) use (&$called) {
            $unsubscribe = null;
            $task = $group->spawn(static function () use (&$called, &$unsubscribe) {
                $unsubscribe = onCancel(static function () use (&$called) {
                    $called = true;
                });

                never();
            });

            delay(1);
            $task->cancel();
            /** @var \Closure(): void $unsubscribe */
            $unsubscribe();
        });

        checkpoint();

        Assert::false($called);
    }

    public function isCalledSoonIfScopeIsAlreadyCancelled(): void
    {
        $observed = [];

        thrown(static function () use (&$observed) {
            timeout(1, static function () use (&$observed) {
                try {
                    never();
                } finally {
                    onCancel(static function () use (&$observed) {
                        $observed[] = 'called';
                    });
                    $observed[] = 'subscribed';
                    shield(checkpoint(...));
                }
            });
        });

        Assert::same($observed, ['subscribed', 'called']);
    }

    public function isNotCalledIfScopeIsAlreadyCancelledAndUnsubscribedAtOnce(): void
    {
        $called = false;

        thrown(static function () use (&$called) {
            timeout(1, static function () use (&$called) {
                try {
                    never();
                } finally {
                    $unsubscribe = onCancel(static function () use (&$called) {
                        $called = true;
                    });
                    $unsubscribe();
                    shield(checkpoint(...));
                }
            });
        });

        Assert::false($called);
    }

    public function isNotCalledInsideShieldOnOuterCancellation(): void
    {
        $called = false;

        timeout(1, static function () use (&$called) {
            shield(static function () use (&$called) {
                onCancel(static function () use (&$called) {
                    $called = true;
                });

                delay(5);
            });
        });
        checkpoint();

        Assert::false($called);
    }

    public function isNotCalledAfterScopeHasEnded(): void
    {
        $called = false;

        $task = group(static fn(Group $group) => $group->spawn(static function () use (&$called) {
            onCancel(static function () use (&$called) {
                $called = true;
            });
        }));
        $called = shield(static function () use ($task, &$called) {
            $task->cancel();
            checkpoint();

            return $called;
        });

        Assert::false($called);
    }

    public function isNotHeldAfterScopeHasEnded(): void
    {
        $callback = static function () {};
        $reference = \WeakReference::create($callback);

        timeout(1_000, static function () use ($callback) {
            onCancel($callback);
        });
        unset($callback);

        Assert::null($reference->get());
    }

    public function isNotHeldByRootScope(): void
    {
        $callback = static function () {};
        $reference = \WeakReference::create($callback);

        $unsubscribe = onCancel($callback);
        unset($callback);

        Assert::null($reference->get());
        $unsubscribe();
    }
}
