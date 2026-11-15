<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Time\sec;

#[Test]
final class LocalSemaphoreTest
{
    /**
     * @param positive-int $permits
     */
    #[DataSet([1])]
    #[DataSet([3])]
    public function atMostPermitsHoldersAtATime(int $permits): void
    {
        $semaphore = new LocalSemaphore($permits);
        $holders = 0;
        $peak = 0;

        group(static function (Group $group) use ($semaphore, &$holders, &$peak) {
            for ($i = 0; $i < 10; ++$i) {
                $group->spawn(static function () use ($semaphore, &$holders, &$peak) {
                    synchronized($semaphore, static function () use (&$holders, &$peak) {
                        $peak = max($peak, ++$holders);
                        delay(sec(1));
                        --$holders;
                    });
                });
            }
        });

        Assert::same($peak, $permits);
    }

    public function criticalSectionsWithOnePermitDoNotInterleave(): void
    {
        $mutex = new LocalSemaphore(1);
        $log = [];

        group(static function (Group $group) use ($mutex, &$log) {
            foreach (['a', 'b'] as $name) {
                $group->spawn(static function () use ($mutex, $name, &$log) {
                    synchronized($mutex, static function () use ($name, &$log) {
                        $log[] = $name . ' in';
                        delay(sec(1));
                        $log[] = $name . ' out';
                    });
                });
            }
        });

        Assert::same($log, ['a in', 'a out', 'b in', 'b out']);
    }

    public function acquireOfFreePermitReturnsWithoutWaiting(): void
    {
        $semaphore = new LocalSemaphore(1);

        $lock = timeout(sec(1), static function () use ($semaphore) {
            untilCancelled();

            return $semaphore->acquire();
        });

        Assert::false($lock->isReleased);
    }

    public function waitersGetPermitsInOrderOfArrival(): void
    {
        $semaphore = new LocalSemaphore(1);
        $order = [];

        group(static function (Group $group) use ($semaphore, &$order) {
            $lock = $semaphore->acquire();

            for ($i = 1; $i <= 3; ++$i) {
                $group->spawn(static function () use ($semaphore, $i, &$order) {
                    synchronized($semaphore, static function () use ($i, &$order) {
                        $order[] = $i;
                        delay(sec(1));
                    });
                });
            }

            delay(sec(1));
            $lock->release();
        });

        Assert::same($order, [1, 2, 3]);
    }

    public function releasedPermitGoesToWaiterNotToNewcomer(): void
    {
        $semaphore = new LocalSemaphore(1);
        $order = [];

        group(static function (Group $group) use ($semaphore, &$order) {
            $lock = $semaphore->acquire();
            $group->spawn(static function () use ($semaphore, &$order) {
                synchronized($semaphore, static function () use (&$order) {
                    $order[] = 'waiter';
                });
            });
            delay(sec(1));

            $lock->release(); // the waiter has not run yet
            synchronized($semaphore, static function () use (&$order) {
                $order[] = 'newcomer';
            });
        });

        Assert::same($order, ['waiter', 'newcomer']);
    }

    public function permitOfCancelledWaiterGoesToNextWaiter(): void
    {
        $semaphore = new LocalSemaphore(1);

        group(static function (Group $group) use ($semaphore) {
            $lock = $semaphore->acquire();
            $cancelled = $group->spawn(static fn() => synchronized($semaphore, static fn() => 'cancelled'));
            $next = $group->spawn(static fn() => synchronized($semaphore, static fn() => 'next'));
            delay(sec(1));

            $cancelled->cancel();
            $lock->release(); // the cancelled waiter is still in the queue: it has not run since

            Assert::instanceOf(thrown($cancelled->await(...)), TaskCancelled::class);
            Assert::same($next->await(), 'next');
        });
    }

    public function waiterCancelledAfterHandoverKeepsPermitUntilItReleases(): void
    {
        $semaphore = new LocalSemaphore(1);
        $acquired = false;

        group(static function (Group $group) use ($semaphore, &$acquired) {
            $lock = $semaphore->acquire();
            $waiter = $group->spawn(static function () use ($semaphore, &$acquired) {
                $lock = $semaphore->acquire();

                try {
                    $acquired = true;
                    never();
                } finally {
                    $lock->release();
                }
            });
            delay(sec(1));

            $lock->release();
            $waiter->cancel();

            thrown($waiter->await(...));
        });

        Assert::true($acquired);
        $this->assertFree($semaphore);
    }

    public function acquireIsCancellable(): void
    {
        $semaphore = new LocalSemaphore(1);
        $lock = $semaphore->acquire();

        $exception = thrown(static fn() => timeout(sec(1), $semaphore->acquire(...)));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same((fn(): array => $this->waiters->items)->call($semaphore), []);
        assertNoCallbacksLeft();

        $lock->release();
        $this->assertFree($semaphore);
    }

    public function lockMayBeReleasedByAnotherFiber(): void
    {
        $semaphore = new LocalSemaphore(1);

        $result = group(static function (Group $group) use ($semaphore) {
            $lock = $semaphore->acquire();
            $group->spawn(static function () use ($lock) {
                delay(sec(1));
                $lock->release();
            });

            return synchronized($semaphore, static fn() => 'acquired after release');
        });

        Assert::same($result, 'acquired after release');
    }

    public function droppedLockReturnsPermit(): void
    {
        $semaphore = new LocalSemaphore(1);
        $semaphore->acquire();

        $this->assertFree($semaphore);
    }

    private function assertFree(LocalSemaphore $semaphore): void
    {
        Assert::same((fn(): int => $this->permits)->call($semaphore), 1);
    }
}
