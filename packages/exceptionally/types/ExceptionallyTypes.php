<?php

declare(strict_types=1);

namespace Thesis;

use function PHPStan\Testing\assertType;

/**
 * Not called: `make phpstan` checks the assertions when it analyses the file.
 *
 * @param callable(): (string|false) $stringOrFalse
 * @param callable(): (int|false) $intOrFalse
 * @param callable(): bool $bool
 * @param callable(): (string|bool) $stringOrBool
 * @param callable(): ?string $nullable
 * @param callable(): mixed $mixed
 * @param callable(): false $false
 */
function exceptionallyRemovesFalseFromTheResult(
    callable $stringOrFalse,
    callable $intOrFalse,
    callable $bool,
    callable $stringOrBool,
    callable $nullable,
    callable $mixed,
    callable $false,
): void {
    assertType('string', exceptionally($stringOrFalse));
    assertType('int', exceptionally($intOrFalse));
    assertType('true', exceptionally($bool));
    assertType('string|true', exceptionally($stringOrBool));
    assertType('string|null', exceptionally($nullable));
    assertType('mixed~false', exceptionally($mixed));
    assertType('string', exceptionally(static fn() => file_get_contents('/path')));
    assertType('42', exceptionally(static fn() => 42));
    // The last: never makes the code after it unreachable
    assertType('never', exceptionally($false));
}
