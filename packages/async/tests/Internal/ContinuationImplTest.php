<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Cancelled;
use Thesis\Async\Group;
use function Thesis\Async\checkpoint;
use function Thesis\Async\group;
use function Thesis\Async\Tests\thrown;

#[Test]
final class ContinuationImplTest
{
    public function cannotBeParkedTwice(): void
    {
        /** @var ContinuationImpl<null> */
        $continuation = new ContinuationImpl();
        Expect::exception(\LogicException::class)->withMessage('Continuation is already parked.');

        group(static function (Group $group) use ($continuation) {
            $group->spawn($continuation->park(...)); // parked in another fiber
            checkpoint(); // one tick: the task parks first

            try {
                $continuation->park();
            } finally {
                $continuation->resume(); // let the task finish
            }
        });
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
