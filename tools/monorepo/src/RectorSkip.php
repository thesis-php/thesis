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
     * @param non-empty-string $rule the class of the rule
     * @param non-empty-list<non-empty-string> $paths files or directories, relative to the package
     * @param non-empty-string $reason why the rule does not apply, read by rector.php
     */
    public function __construct(
        public string $rule,
        public array $paths,
        public string $reason,
    ) {}
}
