<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Git;

/**
 * @internal
 */
final readonly class TreeEntry
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $bytes the entry as the tree has it, "<octal mode> <name>\0<20 bytes of id>":
     *     a tree made of the entries of another must not write a mode in another way (40000 or 040000)
     */
    public function __construct(
        public string $name,
        public string $id,
        public bool $isTree,
        public string $bytes,
    ) {}
}
