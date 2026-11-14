<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\thrown;

#[Test]
final class LocalKeyedSemaphoreTest
{
    /**
     * @param positive-int $permits
     */
    #[DataSet([1])]
    #[DataSet([3])]
    public function atMostPermitsHoldersOfKeyAtATime(int $permits): void
    {
        $semaphore = new LocalKeyedSemaphore($permits);
        $holders = ['a' => 0, 'b' => 0];
        $peak = ['a' => 0, 'b' => 0];

        group(static function (Group $group) use ($semaphore, &$holders, &$peak) {
            for ($i = 0; $i < 10; ++$i) {
                $key = $i % 2 === 0 ? 'a' : 'b';
                $group->spawn(static function () use ($semaphore, $key, &$holders, &$peak) {
                    synchronizedKey($semaphore, $key, static function () use ($key, &$holders, &$peak) {
                        $peak[$key] = max($peak[$key], ++$holders[$key]);
                        delay(0.001);
                        --$holders[$key];
                    });
                });
            }
        });

        Assert::same($peak, ['a' => $permits, 'b' => $permits]);
        $this->assertNoKeysLeft($semaphore);
    }

    public function keysDoNotWaitForEachOther(): void
    {
        $mutex = new LocalKeyedMutex();
        $lock = $mutex->acquire('a');

        $result = timeout(0.01, static fn() => synchronizedKey($mutex, 'b', static fn() => 'b while a is held'));

        Assert::same($result, 'b while a is held');
        $lock->release();
    }

    public function sameKeyWaitsForRelease(): void
    {
        $mutex = new LocalKeyedMutex();
        $lock = $mutex->acquire('a');

        $exception = thrown(static fn() => timeout(0.001, static fn() => $mutex->acquire('a')));

        Assert::instanceOf($exception, TimedOut::class);
        $lock->release();
    }

    public function waitersOfKeyGetPermitsInOrderOfArrival(): void
    {
        $semaphore = new LocalKeyedSemaphore(1);
        $order = [];

        group(static function (Group $group) use ($semaphore, &$order) {
            $lock = $semaphore->acquire('a');

            for ($i = 1; $i <= 3; ++$i) {
                $group->spawn(static function () use ($semaphore, $i, &$order) {
                    synchronizedKey($semaphore, 'a', static function () use ($i, &$order) {
                        $order[] = $i;
                        delay(0.001);
                    });
                });
            }

            delay(0.001);
            $lock->release();
        });

        Assert::same($order, [1, 2, 3]);
    }

    public function keyIsDroppedOnceNobodyHoldsOrWaits(): void
    {
        $semaphore = new LocalKeyedSemaphore(1);

        group(static function (Group $group) use ($semaphore) {
            $lock = $semaphore->acquire('a');
            $waiter = $group->spawn(static fn() => $semaphore->acquire('a'));
            delay(0.001);

            $lock->release(); // handed over: the key is still in use
            Assert::same(self::users($semaphore), ['a' => 1]);

            $waiter->await()->release();
        });

        $this->assertNoKeysLeft($semaphore);
    }

    public function cancelledWaiterLeavesKey(): void
    {
        $semaphore = new LocalKeyedSemaphore(1);
        $lock = $semaphore->acquire('a');

        $exception = thrown(static fn() => timeout(0.001, static fn() => $semaphore->acquire('a')));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same(self::users($semaphore), ['a' => 1]);
        assertNoCallbacksLeft();

        $lock->release();
        $this->assertNoKeysLeft($semaphore);
    }

    public function droppedLockReturnsPermit(): void
    {
        $semaphore = new LocalKeyedSemaphore(1);
        $semaphore->acquire('a');

        $this->assertNoKeysLeft($semaphore);
    }

    public function numericStringKeysAreKeys(): void
    {
        $mutex = new LocalKeyedMutex();
        $lock = $mutex->acquire('1');

        $exception = thrown(static fn() => timeout(0.001, static fn() => $mutex->acquire('1')));

        Assert::instanceOf($exception, TimedOut::class);
        $lock->release();
        timeout(0.001, static fn() => $mutex->acquire('1'))->release();
    }

    public function synchronizedKeyIsReentrantPerKey(): void
    {
        $mutex = new LocalKeyedMutex();
        $nested = false;

        timeout(0.01, static function () use ($mutex, &$nested) {
            synchronizedKey($mutex, 'a', static function () use ($mutex, &$nested) {
                synchronizedKey($mutex, 'a', static function () use ($mutex, &$nested) {
                    synchronizedKey($mutex, 'b', static function () use (&$nested) {
                        $nested = true;
                    });
                });
            });
        });

        Assert::true($nested);
    }

    public function synchronizedKeyReleasesWhenFnFails(): void
    {
        $failure = new \RuntimeException('failed');
        $mutex = new LocalKeyedMutex();

        $exception = thrown(static fn() => synchronizedKey($mutex, 'a', static fn() => throw $failure));

        Assert::same($exception, $failure);
        timeout(0.001, static fn() => $mutex->acquire('a'))->release();
    }

    public function synchronizedKeyInTaskIsNotReentrant(): void
    {
        $mutex = new LocalKeyedMutex();
        $log = [];

        group(static function (Group $group) use ($mutex, &$log) {
            synchronizedKey($mutex, 'a', static function () use ($group, $mutex, &$log) {
                $group->spawn(static function () use ($mutex, &$log) {
                    synchronizedKey($mutex, 'a', static function () use (&$log) {
                        $log[] = 'task';
                    });
                });
                delay(0.001);
                $log[] = 'body';
            });
        });

        Assert::same($log, ['body', 'task']);
    }

    public function fiberSuspendedInsideSynchronizedKeyGoesAwayWithLastReference(): void
    {
        $mutex = new LocalKeyedMutex();
        $fiber = new \Fiber(static function () use ($mutex) {
            synchronizedKey($mutex, 'a', static fn() => \Fiber::suspend());
        });
        $fiber->start();
        $reference = \WeakReference::create($fiber);
        unset($fiber);

        Assert::null($reference->get());
    }

    /**
     * @return array<array-key, positive-int>
     */
    private static function users(LocalKeyedSemaphore $semaphore): array
    {
        return (fn(): array => $this->users)->call($semaphore);
    }

    private function assertNoKeysLeft(LocalKeyedSemaphore $semaphore): void
    {
        Assert::same(self::users($semaphore), []);
        Assert::same((fn(): array => $this->semaphores)->call($semaphore), []);
    }
}
