<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension;

use function PHPStan\Testing\assertType;
use function Thesis\Async\allSettled;

/**
 * Not called: `make phpstan` checks the assertions when it analyses the file.
 *
 * @param array<string, callable(): int> $tasks
 * @param list<callable(): int> $list
 * @param non-empty-array<string, callable(): int> $nonEmpty
 * @param callable(): \DateTimeImmutable $date
 */
function allSettledReturnsShapesOfResultsAndErrors(array $tasks, array $list, array $nonEmpty, callable $date): void
{
    assertType(
        "array{array{user?: 'alice', date?: DateTimeImmutable}, array{user?: Throwable, date?: Throwable}}",
        allSettled(['user' => static fn() => 'alice', 'date' => $date]),
    );

    assertType('array{array<string, int>, array<string, Throwable>}', allSettled($tasks));
    // Failures leave holes in the results, and either array may be empty.
    assertType('array{array<int<0, max>, int>, array<int<0, max>, Throwable>}', allSettled($list));
    assertType('array{array<string, int>, array<string, Throwable>}', allSettled($nonEmpty));
}
