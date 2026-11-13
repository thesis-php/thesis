<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;

#[Test]
final class ScopeTest
{
    public function rootIsTheSameOutsideAnyScope(): void
    {
        $root = Scope::current();

        timeout(1, static fn() => delay(0.001));
        group(static fn() => null);

        Assert::same(Scope::current(), $root);
    }

    public function rootIsCurrentInFiberThatEnteredNoScope(): void
    {
        $root = Scope::current();
        $inFiber = null;

        $fiber = new \Fiber(static function () use (&$inFiber) {
            $inFiber = Scope::current();
        });
        $fiber->start();

        Assert::same($inFiber, $root);
    }
}
