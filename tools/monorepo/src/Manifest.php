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
     * @param positive-int $licenseSince the first year of the copyright in LICENSE
     * @param array<non-empty-string, non-empty-string> $require packages and platform requirements.
     *     A monorepo package is required as 'thesis/<dir>' => '*', its constraint comes from Config::$version.
     *     php defaults to the constraint of monorepo.php
     * @param array<non-empty-string, non-empty-string> $requireDev the same, for the tests
     * @param ?non-empty-string $namespace psr-4 prefix of src/ and tests/, defaults to Thesis\<PascalCaseDir>\
     * @param list<non-empty-string> $files autoload files, relative to the package
     * @param list<non-empty-string> $devFiles autoload-dev files, relative to the package
     * @param array<non-empty-string, non-empty-string> $suggest
     * @param array<non-empty-string, non-empty-string> $conflict
     * @param list<non-empty-string> $optional packages and extensions of requireDev that src/ uses only when
     *     they are installed, behind a check: composer-dependency-analyser allows them in src/.
     *     Tell the users about them in $suggest
     * @param list<FixerSkip|RectorSkip> $skip files where a rule of a tool does not apply. PHPStan is not here:
     *     its errors are ignored in the code, by a phpstan-ignore comment with an identifier and a reason
     */
    public function __construct(
        public string $description,
        public int $licenseSince,
        public array $require = [],
        public array $requireDev = [],
        public ?string $namespace = null,
        public array $files = [],
        public array $devFiles = [],
        public array $suggest = [],
        public array $conflict = [],
        public array $optional = [],
        public array $skip = [],
    ) {}
}
