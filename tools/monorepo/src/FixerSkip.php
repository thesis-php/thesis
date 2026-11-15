<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

/**
 * Files of a package that PHP CS Fixer does not touch. The fixer cannot turn off one rule for one file,
 * so the whole file is excluded.
 *
 * @api
 */
final readonly class FixerSkip
{
    /**
     * @param non-empty-list<non-empty-string> $paths files, relative to the package
     * @param non-empty-string $reason why a rule does not apply, it goes to the generated tools/manifests.php
     */
    public function __construct(
        public array $paths,
        public string $reason,
    ) {}
}
