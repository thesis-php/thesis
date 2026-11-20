<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Split;

/**
 * The splits of the packages of a commit of the monorepo.
 *
 * @internal
 */
final readonly class Splits
{
    /**
     * @param array<string, non-empty-string> $commits package => its split commit, for every package of the commit
     * @param array<string, non-empty-string> $origins split commit => the commit of the monorepo it was made from,
     *     for every commit of the history of every split
     */
    public function __construct(
        public array $commits,
        public array $origins,
    ) {}
}
