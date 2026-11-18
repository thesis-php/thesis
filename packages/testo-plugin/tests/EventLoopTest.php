<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Instant;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Time\ms;

/**
 * The suite has no EventLoopPlugin: a test runs in the event loop only by the attribute.
 */
#[Test]
final class EventLoopTest
{
    #[EventLoop]
    public function attributeRunsTestInEventLoopOnRealTime(): void
    {
        $start = Instant::now();
        $realStart = hrtime(true);

        delay(ms(10));

        Assert::true(Instant::now()->since($start) >= ms(10));
        Assert::int(hrtime(true) - $realStart)->greaterThanOrEqual(10 * 1_000_000);
    }

    public function withoutAttributeWaitIsRefused(): void
    {
        Expect
            ::exception(\LogicException::class)
            ->withMessage('A wait outside the event loop cannot be resumed: wrap the code in group().')
        ;

        delay(ms(1));
    }

    public function withoutAttributeGroupRunsEventLoopItself(): void
    {
        $waited = group(static function () {
            $start = Instant::now();
            delay(ms(1));

            return Instant::now()->since($start);
        });

        Assert::true($waited >= ms(1));
    }
}
