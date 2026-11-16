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
     * @param non-empty-string $version the current series of the package, X.Y: '0.1', '2.0'. Changed by hand
     *     in the commit that starts a series: a break below 1.0, a new minor above (agents/versions.md)
     * @param array<non-empty-string, non-empty-string> $require packages and platform requirements.
     *     A monorepo package is required as 'thesis/<dir>' => '*', the range of its series is written instead
     *     (^0.Y below 1.0, ^X.0 above). A constraint within that range raises the lower bound: '^0.1.5'.
     *     php defaults to the constraint of monorepo.php
     * @param array<non-empty-string, non-empty-string> $requireDev the same, for the tests
     * @param ?non-empty-string $namespace psr-4 prefix of src/ and tests/, defaults to Thesis\<PascalCaseDir>\
     * @param list<non-empty-string> $files autoload files outside src/, relative to the package. The PHP files
     *     of src/ without a class, an interface, a trait or an enum are autoloaded without being listed
     * @param list<non-empty-string> $devFiles the same, for autoload-dev and tests/
     * @param array<non-empty-string, non-empty-string> $suggest
     * @param array<non-empty-string, non-empty-string> $conflict
     * @param list<non-empty-string> $optional packages and extensions of requireDev that src/ uses only when
     *     they are installed, behind a check: composer-dependency-analyser allows them in src/.
     *     Tell the users about them in $suggest
     * @param list<FixerSkip|RectorSkip> $skip files where a rule of a tool does not apply. PHPStan is not here:
     *     its errors are ignored in the code, by a phpstan-ignore comment with an identifier and a reason
     * @param list<non-empty-string> $dist top-level paths of the package that src/ needs at runtime (a polyfill,
     *     resources). Composer installs only them, src/, composer.json, LICENSE, README.md and skills/
     * @param non-empty-string $type composer type: 'phpstan-extension' for a package that a composer plugin
     *     installs by its type
     * @param array<non-empty-string, mixed> $extra composer extra, the config of such a plugin. A file it names
     *     outside src/ goes to $dist too
     */
    public function __construct(
        public string $description,
        public int $licenseSince,
        public string $version,
        public array $require = [],
        public array $requireDev = [],
        public ?string $namespace = null,
        public array $files = [],
        public array $devFiles = [],
        public array $suggest = [],
        public array $conflict = [],
        public array $optional = [],
        public array $skip = [],
        public array $dist = [],
        public string $type = 'library',
        public array $extra = [],
    ) {}
}
