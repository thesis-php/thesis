<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

/**
 * monorepo.php in the root returns an instance of this class.
 */
final readonly class Config
{
    /**
     * @param non-empty-string $php the PHP constraint of every package
     * @param non-empty-string $version the constraint packages use to require each other
     * @param list<array{name: string, email?: string}> $authors authors of every package
     * @param array<non-empty-string, non-empty-string> $require dependencies of the root itself, not of a package
     * @param array<non-empty-string, non-empty-string> $requireDev tools of the root, not of a package
     * @param array<non-empty-string, non-empty-string> $autoloadDev psr-4 prefix => directory, for the root's own tooling
     */
    public function __construct(
        public string $php,
        public string $version,
        public array $authors = [],
        public array $require = [],
        public array $requireDev = [],
        public array $autoloadDev = [],
    ) {}
}
