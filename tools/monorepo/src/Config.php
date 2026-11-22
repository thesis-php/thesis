<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

/**
 * monorepo.php in the root returns an instance of this class. The root composer.json is generated from it
 * and the manifests, entirely: a key that is not modelled here does not exist.
 *
 * @api
 */
final readonly class Config
{
    /**
     * @param non-empty-string $php the PHP constraint of every package
     * @param non-empty-string $description the monorepo description in the root composer.json and on GitHub
     * @param non-empty-string $copyrightHolder in LICENSE of the root and of every package
     * @param positive-int $licenseSince the first year of the copyright in the root LICENSE
     * @param list<non-empty-string> $keywords the monorepo keywords in the root composer.json and the repository's
     *     GitHub topics. The same keyword rules apply as in {@see Manifest}.
     * @param list<array{name: string, email?: string, homepage?: string}> $authors authors of every package
     * @param array<non-empty-string, non-empty-string> $require dependencies of the root itself, not of a package
     * @param array<non-empty-string, non-empty-string> $requireDev tools of the root, not of a package
     * @param array<non-empty-string, non-empty-string> $autoloadDev psr-4 prefix => directory,
     *     for the root's own tooling
     * @param array<non-empty-string, bool> $allowPlugins composer plugins the root may run
     */
    public function __construct(
        public string $php,
        public string $description,
        public string $copyrightHolder,
        public int $licenseSince,
        public array $keywords = [],
        public array $authors = [],
        public array $require = [],
        public array $requireDev = [],
        public array $autoloadDev = [],
        public array $allowPlugins = [],
    ) {}
}
