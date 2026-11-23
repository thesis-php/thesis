<?php

declare(strict_types=1);

namespace Thesis\Logger;

use Testo\Assert;

/**
 * @param callable(): mixed $fn
 */
function thrown(callable $fn): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $exception) {
        return $exception;
    }

    Assert::fail('Nothing was thrown.');
}
