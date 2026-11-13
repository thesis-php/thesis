<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\thrown;

#[Test]
final class OnCancelTest
{
    public function isCalledOnceWhenScopeIsCancelled(): void
    {
        $calls = 0;

        $exception = thrown(static function () use (&$calls) {
            timeout(0.001, static function () use (&$calls) {
                onCancel(static function () use (&$calls) {
                    ++$calls;
                });

                delay(1);
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
            timeout(0.001, static function () use (&$calls) {
                group(static function (Group $group) use (&$calls) {
                    $group->spawn(static function () use (&$calls) {
                        timeout(1, static function () use (&$calls) {
                            onCancel(static function () use (&$calls) {
                                ++$calls;
                            });

                            delay(1);
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
            timeout(0.005, static function () use (&$calls) {
                group(static function (Group $group) use (&$calls) {
                    $task = $group->spawn(static function () use (&$calls) {
                        onCancel(static function () use (&$calls) {
                            ++$calls;
                        });

                        try {
                            delay(1);
                        } finally {
                            shield(static fn() => delay(0.01));
                        }
                    });

                    delay(0.001);
                    $task->cancel();
                    $task->cancel();
                    delay(1);
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

                delay(1);
            });

            delay(0.001);
            $task->cancel();
            $observed = $called;
        });

        Assert::false($observed);
    }

    public function isNotCalledAfterUnsubscribe(): void
    {
        $called = false;

        thrown(static function () use (&$called) {
            timeout(0.001, static function () use (&$called) {
                $unsubscribe = onCancel(static function () use (&$called) {
                    $called = true;
                });
                $unsubscribe();
                $unsubscribe();

                delay(1);
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

                delay(1);
            });

            delay(0.001);
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
            timeout(0.001, static function () use (&$observed) {
                try {
                    delay(1);
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
            timeout(0.001, static function () use (&$called) {
                try {
                    delay(1);
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

        timeout(0.001, static function () use (&$called) {
            shield(static function () use (&$called) {
                onCancel(static function () use (&$called) {
                    $called = true;
                });

                delay(0.005);
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

        timeout(1, static function () use ($callback) {
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
