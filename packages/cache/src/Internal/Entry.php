<?php

declare(strict_types=1);

namespace Thesis\Cache\Internal;

use Thesis\Async\Instant;

/**
 * An entry of `LocalCache` and a node of its list in the order of use.
 *
 * @internal
 * @template V
 */
final class Entry
{
    /**
     * @var ?self<V> the entry used next after this one, null for the most recently used
     */
    public ?self $newer = null;

    /**
     * The key of the entry used before this one, null for the least recently used. A key, not the entry:
     * references both ways would make every pair of neighbours a cycle that only the cycle collector frees.
     */
    public ?string $olderKey = null;

    /**
     * @param V $value
     * @param ?Instant $expiresAt null if the entry does not expire
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly ?Instant $expiresAt,
    ) {}

    public function hasExpired(Instant $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
