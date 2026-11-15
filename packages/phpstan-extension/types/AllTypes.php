<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension;

use function PHPStan\Testing\assertType;
use function Thesis\Async\all;

/**
 * Not called: `make phpstan` checks the assertions when it analyses the file.
 *
 * @param array<string, callable(): int> $tasks
 * @param list<callable(): int> $list
 * @param non-empty-list<callable(): int> $nonEmptyList
 * @param non-empty-array<string, callable(): int> $nonEmpty
 * @param callable(): \DateTimeImmutable $date
 * @param array{a: callable(): int}|array{b: callable(): string} $either
 * @param array{a: callable(): int, b?: callable(): string} $optional
 */
function allReturnsShapeOfResults(
    array $tasks,
    array $list,
    array $nonEmptyList,
    array $nonEmpty,
    callable $date,
    array $either,
    array $optional,
): void {
    assertType("array{user: 'alice', orders: array{1, 2, 3}}", all([
        'user' => static fn() => 'alice',
        'orders' => static fn() => [1, 2, 3],
    ]));
    assertType('array{int<0, 9>, true}', all([static fn() => random_int(0, 9), static fn() => true]));
    assertType('array{date: DateTimeImmutable}', all(['date' => $date]));
    assertType('array{date: DateTimeImmutable}', all(tasks: ['date' => $date]));
    assertType('array{a: int}|array{b: string}', all($either));
    assertType('array{a: int, b?: string}', all($optional));

    assertType('array<string, int>', all($tasks));
    assertType('list<int>', all($list));
    assertType('non-empty-list<int>', all($nonEmptyList));
    assertType('non-empty-array<string, int>', all($nonEmpty));
    // An unpacked argument keeps the type of the PHPDoc.
    assertType('array<0, 1>', all(...[[static fn() => 1]]));
}
