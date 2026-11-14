<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\VirtualTime\advance;

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
        $remaining = timeout(1_000, static function () {
            delay(10);

            return remaining();
        });

        Assert::same($remaining, 990.0);
    }

    public function decreasesWhileTimeGoesBy(): void
    {
        [$before, $after] = timeout(1_000, static function () {
            $before = remaining();
            advance(2);

            return [$before, remaining()];
        });

        Assert::same([$before, $after], [1_000.0, 998.0]);
    }

    public function nestedTimeoutWithLaterDeadlineShowsParentOne(): void
    {
        $remaining = timeout(500, static fn() => timeout(10_000, remaining(...)));

        Assert::same($remaining, 500.0);
    }

    public function nestedTimeoutWithEarlierDeadlineShowsItsOwn(): void
    {
        [$inner, $outer] = timeout(10_000, static fn() => [timeout(500, remaining(...)), remaining()]);

        Assert::same([$inner, $outer], [500.0, 10_000.0]);
    }

    public function isNullInsideShield(): void
    {
        $remaining = timeout(1_000, static fn() => shield(remaining(...)));

        Assert::null($remaining);
    }

    public function timeoutInsideShieldShowsOnlyItsOwn(): void
    {
        $remaining = timeout(500, static fn() => shield(static fn() => timeout(10_000, remaining(...))));

        Assert::same($remaining, 10_000.0);
    }

    public function taskSeesDeadlineOfItsGroup(): void
    {
        $remaining = timeout(500, static fn() => group(static function (Group $group) {
            $daemon = $group->daemon(remaining(...));
            $handle = $group->spawn(remaining(...));

            return [$handle->await(), $daemon->await()];
        }));

        Assert::same($remaining, [500.0, 500.0]);
    }

    public function isZeroOnceDeadlinePassedBeforeTimerFired(): void
    {
        $observed = null;

        thrown(static function () use (&$observed) {
            timeout(1, static function () use (&$observed) {
                advance(2);
                $observed = [remaining(), isCancelled()];
                never();
            });
        });

        Assert::same($observed, [0.0, false]);
    }

    public function isGoneAfterTimeoutReturns(): void
    {
        timeout(1_000, static fn() => null);

        Assert::null(remaining());
    }
}
