<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\thrown;

#[Test]
final class IsCancelledTest
{
    public function isFalseInHealthyScope(): void
    {
        Assert::false(isCancelled());
        Assert::false(timeout(1, isCancelled(...)));
    }

    public function isTrueInCancelledScope(): void
    {
        $observed = [];

        thrown(static function () use (&$observed) {
            timeout(0.001, static function () use (&$observed) {
                $observed[] = isCancelled();

                try {
                    delay(1);
                } finally {
                    $observed[] = isCancelled();
                }
            });
        });

        Assert::same($observed, [false, true]);
    }

    public function isTrueInScopeNestedUnderCancelledOne(): void
    {
        $observed = null;

        thrown(static function () use (&$observed) {
            timeout(0.001, static function () use (&$observed) {
                try {
                    delay(1);
                } finally {
                    $observed = timeout(1, isCancelled(...));
                }
            });
        });

        Assert::true($observed);
    }

    public function isTrueInCancelledTask(): void
    {
        $observed = null;

        group(static function (Group $group) use (&$observed) {
            $task = $group->spawn(static function () use (&$observed) {
                try {
                    delay(1);
                } finally {
                    $observed = isCancelled();
                }
            });

            delay(0.001);
            $task->cancel();
        });

        Assert::true($observed);
    }

    public function isFalseInsideShield(): void
    {
        $observed = null;

        thrown(static function () use (&$observed) {
            timeout(0.001, static function () use (&$observed) {
                try {
                    delay(1);
                } finally {
                    $observed = shield(isCancelled(...));
                }
            });
        });

        Assert::false($observed);
    }

    public function doesNotYieldToEventLoop(): void
    {
        $ran = group(static function (Group $group) {
            $ran = false;
            $group->spawn(static function () use (&$ran) {
                $ran = true;
            });

            isCancelled();

            return $ran;
        });

        Assert::false($ran);
    }
}
