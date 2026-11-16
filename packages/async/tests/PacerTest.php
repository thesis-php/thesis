<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Time\sec;

#[Test]
final class PacerTest
{
    public function doesNotYieldWithinLimit(): void
    {
        $pacer = new Pacer(perTick: 3);

        Assert::false(self::yields(static function () use ($pacer) {
            $pacer->pace();
            $pacer->pace();
            $pacer->pace();
        }));
    }

    public function yieldsOnceLimitIsReachedInOneTick(): void
    {
        $pacer = new Pacer(perTick: 3);
        $pacer->pace();
        $pacer->pace();
        $pacer->pace();

        Assert::true(self::yields($pacer->pace(...)));
    }

    public function countsAgainAfterYielding(): void
    {
        $pacer = new Pacer(perTick: 2);
        $pacer->pace();
        $pacer->pace();
        $pacer->pace();

        Assert::false(self::yields($pacer->pace(...)));
        Assert::true(self::yields($pacer->pace(...)));
    }

    public function countsEachTickAnew(): void
    {
        $pacer = new Pacer(perTick: 2);
        $pacer->pace();
        $pacer->pace();

        delay(sec(0));

        Assert::false(self::yields(static function () use ($pacer) {
            $pacer->pace();
            $pacer->pace();
        }));
    }

    public function letsOtherTasksRun(): void
    {
        $pacer = new Pacer(perTick: 16);

        $iterations = group(static function (Group $group) use ($pacer) {
            $stop = false;
            $group->spawn(static function () use (&$stop) {
                $stop = true;
            });

            $iterations = 0;

            while (!$stop) {
                $pacer->pace();
                ++$iterations;
            }

            return $iterations;
        });

        // The task starts at the first wait: the pace after the limit, so the operation after it runs too.
        Assert::same($iterations, 17);
    }

    public function cancelledPaceIsBeforeOperation(): void
    {
        $pacer = new Pacer(perTick: 3);
        $operations = 0;

        $exception = thrown(static function () use ($pacer, &$operations) {
            timeout(sec(1), static function () use ($pacer, &$operations) {
                untilCancelled();

                while ($operations < 1000) {
                    $pacer->pace();
                    ++$operations;
                }
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same($operations, 3);
    }

    public function leavesNoCallbacksAfterTick(): void
    {
        $pacer = new Pacer(perTick: 1);
        $pacer->pace();
        $pacer->pace();

        delay(sec(0));

        assertNoCallbacksLeft();
    }

    public function callsOfOtherCallersInTickCountAfterYield(): void
    {
        $pacer = new Pacer(perTick: 2);
        $pacer->pace();
        $pacer->pace();
        // Another caller of the shared pacer, in the next tick before the yield below returns: it fills that tick.
        EventLoop::defer(static function () use ($pacer) {
            $pacer->pace();
            $pacer->pace();
        });
        $pacer->pace();

        Assert::true(self::yields($pacer->pace(...)));
    }

    public function countsAgainAfterItsTickIsDroppedWithDriver(): void
    {
        $pacer = new Pacer(perTick: 2);
        // The defer that resets the count is left in the loop of the run, and the run drops it.
        VirtualTime\run(static function () use ($pacer) {
            $pacer->pace();
        });
        $pacer->pace();
        $pacer->pace();

        Assert::false(self::yields($pacer->pace(...)));
    }

    /**
     * Whether $fn has let the event loop run a tick.
     *
     * @param callable(): void $fn
     */
    private static function yields(callable $fn): bool
    {
        $ticked = false;
        $callbackId = EventLoop::defer(static function () use (&$ticked) {
            $ticked = true;
        });

        try {
            $fn();
        } finally {
            EventLoop::cancel($callbackId);
        }

        return $ticked;
    }
}
