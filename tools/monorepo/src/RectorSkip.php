<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

/**
 * A Rector rule that is not applied to some files of a package.
 *
 * @api
 */
final readonly class RectorSkip
{
    /**
     * @param non-empty-string $rule the class of the rule. Rector is not in the vendor of the monorepo,
     *     but `::class` of a missing class is still a string
     * @param non-empty-list<non-empty-string> $paths files or directories, relative to the package
     * @param non-empty-string $reason why the rule does not apply, it goes to the generated tools/manifests.php
     */
    public function __construct(
        public string $rule,
        public array $paths,
        public string $reason,
    ) {}
}
