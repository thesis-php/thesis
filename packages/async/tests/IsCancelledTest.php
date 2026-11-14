<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;

#[Test]
final class IsCancelledTest
{
    public function isFalseInHealthyScope(): void
    {
        Assert::false(isCancelled());
        Assert::false(timeout(1_000, isCancelled(...)));
    }

    public function isTrueInCancelledScope(): void
    {
        $observed = [];

        thrown(static function () use (&$observed) {
            timeout(1, static function () use (&$observed) {
                $observed[] = isCancelled();

                try {
                    never();
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
            timeout(1, static function () use (&$observed) {
                try {
                    never();
                } finally {
                    $observed = timeout(1_000, isCancelled(...));
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
                    never();
                } finally {
                    $observed = isCancelled();
                }
            });

            delay(1);
            $task->cancel();
        });

        Assert::true($observed);
    }

    public function isFalseInsideShield(): void
    {
        $observed = null;

        thrown(static function () use (&$observed) {
            timeout(1, static function () use (&$observed) {
                try {
                    never();
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
