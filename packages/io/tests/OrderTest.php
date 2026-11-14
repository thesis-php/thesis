<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Order::class)]
final class OrderTest
{
    public function networkIsBig(): void
    {
        Assert::same(Order::Network, Order::Big);
    }

    public function detectsNativeOrder(): void
    {
        $expected = pack('S', 1) === "\x01\x00" ? Order::Little : Order::Big;

        Assert::same(Order::native(), $expected);
        Assert::same(Order::native(), $expected);
    }
}
