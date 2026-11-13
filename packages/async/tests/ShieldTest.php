<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\thrown;

#[Test]
final class ShieldTest
{
    public function returnsResult(): void
    {
        Assert::same(shield(static fn() => 'result'), 'result');
    }

    public function protectsFromOuterCancellation(): void
    {
        $cleanedUp = false;

        $exception = thrown(static function () use (&$cleanedUp) {
            timeout(0.001, static function () use (&$cleanedUp) {
                try {
                    delay(1);
                } finally {
                    shield(static function () use (&$cleanedUp) {
                        delay(0.005);
                        $cleanedUp = true;
                    });
                }
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::true($cleanedUp);
    }

    public function protectsTasksStartedInside(): void
    {
        $cleanedUp = false;

        thrown(static function () use (&$cleanedUp) {
            timeout(0.001, static function () use (&$cleanedUp) {
                try {
                    delay(1);
                } finally {
                    shield(static function () use (&$cleanedUp) {
                        group(static function (Group $group) use (&$cleanedUp) {
                            $group->spawn(static function () use (&$cleanedUp) {
                                delay(0.005);
                                $cleanedUp = true;
                            });
                        });
                    });
                }
            });
        });

        Assert::true($cleanedUp);
    }

    public function protectsFromGroupFailure(): void
    {
        $cleanedUp = false;

        $exception = thrown(static function () use (&$cleanedUp) {
            group(static function (Group $group) use (&$cleanedUp) {
                $group->spawn(static function () {
                    delay(0.001);

                    throw new \RuntimeException('task failed');
                });

                try {
                    delay(1);
                } finally {
                    shield(static function () use (&$cleanedUp) {
                        delay(0.005);
                        $cleanedUp = true;
                    });
                }
            });
        });

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::true($cleanedUp);
    }

    public function outerCancellationIsVisibleAgainAfterShield(): void
    {
        $exception = thrown(static function () {
            timeout(0.001, static function () {
                try {
                    delay(1);
                } catch (Cancelled) {
                }

                shield(static fn() => delay(0.001));
                delay(1);
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
    }

    public function deadlineInsideShieldStillWorks(): void
    {
        $exception = thrown(static function () {
            shield(static fn() => timeout(0.001, static fn() => delay(1)));
        });

        Assert::instanceOf($exception, TimedOut::class);
    }

    public function deadlineInsideShieldOfCancelledScopeIsReportedAsTimedOut(): void
    {
        $inner = null;

        thrown(static function () use (&$inner) {
            timeout(0.001, static function () use (&$inner) {
                try {
                    delay(1);
                } finally {
                    $inner = thrown(static fn() => shield(static fn() => timeout(0.001, static fn() => delay(1))));
                }
            });
        });

        Assert::instanceOf($inner, TimedOut::class);
    }
}
