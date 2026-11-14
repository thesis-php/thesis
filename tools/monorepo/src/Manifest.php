<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

/**
 * packages/<dir>/package.php returns an instance of this class. The package's composer.json is generated from it.
 *
 * @api
 */
final readonly class Manifest
{
    /**
     * @param non-empty-string $description one sentence, it also goes to the package catalog in AGENTS.md
     * @param list<non-empty-string> $uses monorepo packages this package depends on, by directory name
     * @param array<non-empty-string, non-empty-string> $require external packages and platform requirements
     *     (php is added from monorepo.php)
     * @param list<non-empty-string> $usesDev monorepo packages needed only by the tests
     * @param array<non-empty-string, non-empty-string> $requireDev
     * @param ?non-empty-string $namespace psr-4 prefix of src/ and tests/, defaults to Thesis\<PascalCaseDir>\
     * @param list<non-empty-string> $files autoload files, relative to the package
     * @param list<non-empty-string> $devFiles autoload-dev files, relative to the package
     * @param array<non-empty-string, non-empty-string> $suggest
     * @param array<non-empty-string, non-empty-string> $conflict
     * @param array<non-empty-string, mixed> $composer other composer.json keys, merged last
     */
    public function __construct(
        public string $description,
        public Layer $layer,
        public array $uses = [],
        public array $require = [],
        public array $usesDev = [],
        public array $requireDev = [],
        public ?string $namespace = null,
        public array $files = [],
        public array $devFiles = [],
        public array $suggest = [],
        public array $conflict = [],
        public array $composer = [],
    ) {}
}
