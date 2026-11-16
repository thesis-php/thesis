<?php

declare(strict_types=1);

namespace Thesis\Cache\Tests;

use Testo\Assert;

/**
 * For tests that have to inspect side effects after an expected exception.
 *
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
