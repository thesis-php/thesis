<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\busy;
use function Thesis\Async\Tests\thrown;

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
        $remaining = timeout(1, static function () {
            delay(0.01);

            return remaining();
        });

        Assert::float($remaining)->greaterThan(0.5)->lessThan(0.991);
    }

    public function decreasesWhileTimeGoesBy(): void
    {
        [$before, $after] = timeout(1, static function () {
            $before = remaining();
            busy(0.002);

            return [$before, remaining()];
        });

        Assert::float($before)->lessThan(1.0);
        Assert::float($after)->lessThan((float) $before - 0.001);
    }

    public function nestedTimeoutWithLaterDeadlineShowsParentOne(): void
    {
        $remaining = timeout(0.5, static fn() => timeout(10, remaining(...)));

        Assert::float($remaining)->greaterThan(0.4)->lessThan(0.501);
    }

    public function nestedTimeoutWithEarlierDeadlineShowsItsOwn(): void
    {
        [$inner, $outer] = timeout(10, static fn() => [timeout(0.5, remaining(...)), remaining()]);

        Assert::float($inner)->greaterThan(0.4)->lessThan(0.501);
        Assert::float($outer)->greaterThan(9.9);
    }

    public function isNullInsideShield(): void
    {
        $remaining = timeout(1, static fn() => shield(remaining(...)));

        Assert::null($remaining);
    }

    public function timeoutInsideShieldShowsOnlyItsOwn(): void
    {
        $remaining = timeout(0.5, static fn() => shield(static fn() => timeout(10, remaining(...))));

        Assert::float($remaining)->greaterThan(9.9);
    }

    public function taskSeesDeadlineOfItsGroup(): void
    {
        [$task, $daemon] = timeout(0.5, static fn() => group(static function (Group $group) {
            $daemon = $group->daemon(remaining(...));
            $task = $group->spawn(remaining(...));

            return [$task->await(), $daemon->await()];
        }));

        Assert::float($task)->greaterThan(0.4)->lessThan(0.501);
        Assert::float($daemon)->greaterThan(0.4)->lessThan(0.501);
    }

    public function isZeroOnceDeadlinePassedBeforeTimerFired(): void
    {
        $observed = null;

        thrown(static function () use (&$observed) {
            timeout(0.001, static function () use (&$observed) {
                busy(0.002);
                $observed = [remaining(), isCancelled()];
                delay(1);
            });
        });

        Assert::same($observed, [0.0, false]);
    }

    public function isGoneAfterTimeoutReturns(): void
    {
        timeout(1, static fn() => null);

        Assert::null(remaining());
    }
}
