<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Cancelled;
use function Thesis\Async\checkpoint;
use function Thesis\Async\Tests\thrown;

#[Test]
final class ContinuationImplTest
{
    public function cannotBeParkedTwice(): void
    {
        /** @var ContinuationImpl<null> */
        $continuation = new ContinuationImpl();
        EventLoop::queue(static function () use ($continuation) {
            $continuation->park(); // parked in another fiber
        });
        checkpoint(); // one tick: the queued fiber parks first

        Expect::exception(\LogicException::class)->withMessage('Continuation is already parked.');

        try {
            $continuation->park();
        } finally {
            $continuation->resume(); // let the other fiber finish
        }
    }

    public function interruptMakesParkThrowCancelled(): void
    {
        /** @var ContinuationImpl<string> */
        $continuation = new ContinuationImpl();

        Assert::true($continuation->interrupt());
        Assert::false($continuation->resume('value'));
        Assert::instanceOf(thrown($continuation->park(...)), Cancelled::class);
    }

    public function parkAfterResumeReturnsValueEveryTime(): void
    {
        /** @var ContinuationImpl<string> */
        $continuation = new ContinuationImpl();
        $continuation->resume('value');

        Assert::same($continuation->park(), 'value');
        Assert::same($continuation->park(), 'value');
    }
}
