<?php

declare(strict_types=1);

namespace Thesis\Cache\Internal;

/**
 * Expiries of the entries of `LocalCache`, the earliest on top.
 *
 * @internal
 * @template V
 * @extends \SplHeap<Expiry<V>>
 */
final class Expiries extends \SplHeap
{
    #[\Override]
    protected function compare(mixed $value1, mixed $value2): int
    {
        // The assert only narrows the types for PHPStan: the heap compares its own values, which are all Expiry.
        // @infection-ignore LogicalAnd
        \assert($value1 instanceof Expiry && $value2 instanceof Expiry);

        return $value2->at <=> $value1->at;
    }
}
