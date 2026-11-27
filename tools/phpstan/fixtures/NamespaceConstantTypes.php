<?php

declare(strict_types=1);

namespace Thesis\PhpStanRules\Fixtures;

use DateTimeImmutable as Key;
use function PHPStan\Testing\assertType;

/**
 * @var \WeakMap<Key, string>
 */
const LABELS = new \WeakMap();

function constantKeepsKeyAndValueTypes(Key $key): void
{
    assertType('string|null', LABELS[$key] ?? null);
    $labels = LABELS;
    $labels[$key] = 'label';
    // @phpstan-ignore offsetAssign.dimType
    $labels[new \stdClass()] = 'label';
    // @phpstan-ignore offsetAssign.valueType
    $labels[$key] = 1;
}

/**
 * @var list<string>
 */
const NAMES = ['first'];

/**
 * @var positive-int
 */
const TOTAL = 1 + 2;

const DEFAULT_NAME = 'first';

function namespaceConstantsKeepTheirDeclaredTypes(): void
{
    assertType('list<string>', NAMES);
    assertType('int<1, max>', TOTAL);
    assertType("'first'", DEFAULT_NAME);
}
