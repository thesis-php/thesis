<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\thrown;

#[Test]
final class SynchronizedTest
{
    public function returnsResultAndReleases(): void
    {
        $semaphore = self::semaphore();

        $result = synchronized($semaphore, static fn() => [$semaphore->acquires, $semaphore->releases]);

        Assert::same($result, [1, 0]);
        Assert::same($semaphore->releases, 1);
    }

    public function releasesWhenFnFails(): void
    {
        $failure = new \RuntimeException('failed');

        $semaphore = self::semaphore();

        $exception = thrown(static fn() => synchronized($semaphore, static fn() => throw $failure));

        Assert::same($exception, $failure);
        Assert::same($semaphore->releases, 1);
    }

    public function releasesWhenFnIsCancelled(): void
    {
        $semaphore = self::semaphore();

        $exception = thrown(static fn() => timeout(0.001, static fn() => synchronized($semaphore, static fn() => delay(1))));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same($semaphore->releases, 1);
    }

    public function doesNotCallFnIfAcquireFails(): void
    {
        $failure = new \RuntimeException('unreachable');
        $semaphore = new readonly class ($failure) implements Semaphore {
            public function __construct(
                private \Throwable $failure,
            ) {}

            public function acquire(): Lock
            {
                throw $this->failure;
            }
        };
        $called = false;

        $exception = thrown(static function () use ($semaphore, &$called) {
            synchronized($semaphore, static function () use (&$called) {
                $called = true;
            });
        });

        Assert::same($exception, $failure);
        Assert::false($called);
    }

    public function isReentrantWithinFiber(): void
    {
        $semaphore = self::semaphore();

        $result = synchronized($semaphore, static fn() => synchronized(
            $semaphore,
            static fn() => [$semaphore->acquires, $semaphore->releases],
        ));

        Assert::same($result, [1, 0]);
        Assert::same($semaphore->releases, 1);
    }

    public function nestedFailureKeepsOuterLockUntilOuterReturns(): void
    {
        $semaphore = self::semaphore();

        $result = synchronized($semaphore, static function () use ($semaphore) {
            thrown(static fn() => synchronized($semaphore, static fn() => throw new \RuntimeException()));

            return $semaphore->releases;
        });

        Assert::same($result, 0);
        Assert::same($semaphore->releases, 1);
    }

    public function reentrancyIsPerSemaphore(): void
    {
        $first = self::semaphore();
        $second = self::semaphore();

        synchronized($first, static fn() => synchronized($second, static fn() => null));

        Assert::same([$first->acquires, $second->acquires], [1, 1]);
    }

    public function sameFiberAcquiresAgainAfterSynchronizedReturns(): void
    {
        $semaphore = self::semaphore();

        synchronized($semaphore, static fn() => null);
        synchronized($semaphore, static fn() => null);

        Assert::same([$semaphore->acquires, $semaphore->releases], [2, 2]);
    }

    public function taskStartedInsideIsNotReentrant(): void
    {
        $mutex = new LocalMutex();
        $log = [];

        group(static function (Group $group) use ($mutex, &$log) {
            synchronized($mutex, static function () use ($group, $mutex, &$log) {
                $group->spawn(static function () use ($mutex, &$log) {
                    synchronized($mutex, static function () use (&$log) {
                        $log[] = 'task';
                    });
                });
                delay(0.001);
                $log[] = 'body';
            });
        });

        Assert::same($log, ['body', 'task']);
    }

    public function reentrancyWorksInTask(): void
    {
        $mutex = new LocalMutex();

        $nested = false;

        group(static function (Group $group) use ($mutex, &$nested) {
            $group->spawn(static function () use ($mutex, &$nested) {
                synchronized($mutex, static function () use ($mutex, &$nested) {
                    synchronized($mutex, static function () use (&$nested) {
                        $nested = true;
                    });
                });
            });
        });

        Assert::true($nested);
    }

    /**
     * @return Semaphore&object{acquires: int, releases: int}
     */
    private static function semaphore(): Semaphore
    {
        return new class implements Semaphore {
            public int $acquires = 0;

            public int $releases = 0;

            public function acquire(): Lock
            {
                ++$this->acquires;

                return new Lock(function () {
                    ++$this->releases;
                });
            }
        };
    }
}
