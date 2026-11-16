<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

/**
 * A version of one package that the release publishes: a tag in its split repository and a GitHub Release.
 *
 * @internal
 */
final readonly class Release
{
    /**
     * @param non-empty-string $package the directory in packages/
     * @param non-empty-string $tag
     * @param non-empty-string $commit the split commit that the tag points to
     * @param bool $tagged whether the tag exists already, at that commit: a re-run continues the release
     * @param bool $latest whether it is the highest stable version of the package
     * @param list<non-empty-string> $requires the monorepo packages it requires: none is released after their failure
     */
    public function __construct(
        public string $package,
        public Version $version,
        public string $tag,
        public string $commit,
        public string $notes,
        public bool $tagged,
        public bool $latest,
        public array $requires,
    ) {}
}
