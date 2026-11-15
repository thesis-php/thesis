<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Split;

/**
 * A commit of a split. It names its parents by id, not by object: freeing a chain of tens of thousands
 * of objects that hold each other recurses in the engine and crashes PHP.
 *
 * @internal
 */
final readonly class SplitCommit
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $tree
     * @param list<non-empty-string> $parents
     * @param positive-int $generation the length of the longest path to a root: an ancestor has a lower one
     */
    public function __construct(
        public string $id,
        public string $tree,
        public array $parents,
        public int $generation,
    ) {}
}
