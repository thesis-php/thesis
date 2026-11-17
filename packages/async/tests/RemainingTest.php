<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Time\sec;

#[Test]
final class RemainingTest
{
    public function isNullWithoutDeadline(): void
    {
        Assert::null(remaining());
        Assert::null(group(static fn() => remaining()));
    }

    public function isTimeLeftUnderTimeout(): void
    {
        $remaining = timeout(sec(1000), static function () {
            delay(sec(10));

            return remaining();
        });

        Assert::equals($remaining, sec(990));
    }

    public function decreasesWhileTimeGoesBy(): void
    {
        [$before, $after] = timeout(sec(1000), static function () {
            $before = remaining();
            burnCpu(sec(2));

            return [$before, remaining()];
        });

        Assert::equals([$before, $after], [sec(1000), sec(998)]);
    }

    public function nestedTimeoutWithLaterDeadlineShowsParentOne(): void
    {
        $remaining = timeout(sec(500), static fn() => timeout(sec(10_000), remaining(...)));

        Assert::equals($remaining, sec(500));
    }

    public function nestedTimeoutWithEarlierDeadlineShowsItsOwn(): void
    {
        [$inner, $outer] = timeout(sec(10_000), static fn() => [timeout(sec(500), remaining(...)), remaining()]);

        Assert::equals([$inner, $outer], [sec(500), sec(10_000)]);
    }

    public function isNullInsideShield(): void
    {
        $remaining = timeout(sec(1000), static fn() => shield(remaining(...)));

        Assert::null($remaining);
    }

    public function timeoutInsideShieldShowsOnlyItsOwn(): void
    {
        $remaining = timeout(sec(500), static fn() => shield(static fn() => timeout(sec(10_000), remaining(...))));

        Assert::equals($remaining, sec(10_000));
    }

    public function taskSeesDeadlineOfItsGroup(): void
    {
        $remaining = timeout(sec(500), static fn() => group(static function (Group $group) {
            $daemon = $group->daemon(remaining(...));
            $handle = $group->spawn(remaining(...));

            return [$handle->await(), $daemon->await()];
        }));

        Assert::equals($remaining, [sec(500), sec(500)]);
    }

    public function isZeroOnceDeadlinePassedBeforeTimerFired(): void
    {
        $observed = null;

        thrown(static function () use (&$observed) {
            timeout(sec(1), static function () use (&$observed) {
                burnCpu(sec(2));
                $observed = [remaining(), isCancelled()];
                never();
            });
        });

        Assert::equals($observed, [sec(0), false]);
    }

    public function isGoneAfterTimeoutReturns(): void
    {
        timeout(sec(1000), static fn() => null);

        Assert::null(remaining());
    }
}
