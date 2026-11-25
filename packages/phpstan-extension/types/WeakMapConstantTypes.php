<?php

declare(strict_types=1);

namespace Thesis\PhpstanExtension;

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
