<?php

declare(strict_types=1);

namespace Thesis\Cache\Internal;

use Thesis\Async\Instant;

/**
 * A node of {@see Expiries}. It holds the entry weakly: an entry that has left the cache is freed at once,
 * with its value, and its node is skipped when it comes to the top.
 *
 * @internal
 * @template V
 */
final readonly class Expiry
{
    /**
     * @param \WeakReference<Entry<V>> $entry
     */
    public function __construct(
        public Instant $at,
        public \WeakReference $entry,
    ) {}
}
