<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension;

use function PHPStan\Testing\assertType;
use function Thesis\Async\map;

/**
 * Not called: `make phpstan` checks the assertions when it analyses the file.
 *
 * @param array<string, string> $strings
 * @param list<string> $list
 * @param non-empty-list<string> $nonEmptyList
 * @param non-empty-array<string, string> $nonEmpty
 * @param array{a: string, b?: string} $optional
 * @param \Generator<int, string> $generator
 * @param array{array{a: string}, 2, callable(string): int<0, max>} $arguments
 */
function mapKeepsKeysOfItems(
    array $strings,
    array $list,
    array $nonEmptyList,
    array $nonEmpty,
    array $optional,
    \Generator $generator,
    array $arguments,
): void {
    $length = static fn(string $string) => \strlen($string);

    assertType('array{a: int<0, max>, b: int<0, max>}', map(['a' => 'x', 'b' => 'yy'], 2, $length));
    assertType('array{int<0, max>, int<0, max>}', map(['x', 'yy'], 2, $length));
    assertType('array{a: int<0, max>, b?: int<0, max>}', map($optional, 2, $length));
    assertType('array{a: int<0, max>}', map(fn: $length, concurrency: 2, items: ['a' => 'x']));
    assertType('array{}', map([], 2, $length));

    assertType('array<string, int<0, max>>', map($strings, 2, $length));
    assertType('list<int<0, max>>', map($list, 2, $length));
    assertType('non-empty-list<int<0, max>>', map($nonEmptyList, 2, $length));
    assertType('non-empty-array<string, int<0, max>>', map($nonEmpty, 2, $length));
    // A generator may repeat a key: only the PHPDoc is sure.
    assertType('array<int, int<0, max>>', map($generator, 2, $length));
    // Which of the unpacked arguments are the items is not known.
    assertType("array<'a', int<0, max>>", map(...$arguments));
}
