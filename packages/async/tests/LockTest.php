<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;

#[Test]
final class LockTest
{
    public function releaseReturnsPermitOnce(): void
    {
        $releases = 0;
        $lock = new Lock(static function () use (&$releases) {
            ++$releases;
        });

        Assert::false($lock->isReleased);

        $results = [$lock->release(), $lock->release()];

        Assert::same($results, [true, false]);
        Assert::same($releases, 1);
        Assert::true($lock->isReleased);
    }

    public function releaseThatWaitsWorksInCancelledScope(): void
    {
        $released = false;
        $lock = new Lock(static function () use (&$released) {
            delay(1); // a round trip to the server that holds a distributed lock
            $released = true;
        });

        timeout(1, static function () use ($lock) {
            try {
                never();
            } catch (Cancelled) {
            }

            $lock->release();
        });

        Assert::true($released);
    }

    public function failedReleaseIsNotRetried(): void
    {
        $releases = 0;
        $failure = new \RuntimeException('connection lost');
        $lock = new Lock(static function () use (&$releases, $failure) {
            ++$releases;

            throw $failure;
        });

        Assert::same(thrown($lock->release(...)), $failure);
        Assert::true($lock->isReleased);
        Assert::false($lock->release());
        Assert::same($releases, 1);
    }

    public function destroyedUnreleasedLockIsReleased(): void
    {
        $releases = 0;
        $lock = new Lock(static function () use (&$releases) {
            ++$releases;
        });

        unset($lock);

        Assert::same($releases, 1);
    }

    public function destroyedReleasedLockIsNotReleasedAgain(): void
    {
        $releases = 0;
        $lock = new Lock(static function () use (&$releases) {
            ++$releases;
        });
        $lock->release();

        unset($lock);

        Assert::same($releases, 1);
    }
}
