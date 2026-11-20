<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

/**
 * A version of one package that the release publishes: a tag in its split repository and a GitHub Release,
 * then a tag and a GitHub Release of the monorepo that repeat them.
 *
 * @internal
 */
final readonly class Release
{
    /**
     * @var non-empty-string the tag of the monorepo that repeats the tag of the split repository
     */
    public string $mirrorTag;

    /**
     * @param non-empty-string $package the directory in packages/
     * @param non-empty-string $tag
     * @param non-empty-string $commit the split commit that the tag points to
     * @param ?non-empty-string $origin the commit of the monorepo that the split commit was made from,
     *     null if the tag is of a later commit than the one released: its history does not tell
     * @param bool $tagged whether the tag exists already, at that commit: a re-run continues the release
     * @param bool $latest whether it is the highest stable version of the package
     */
    public function __construct(
        public string $package,
        public Version $version,
        public string $tag,
        public string $commit,
        public ?string $origin,
        public string $notes,
        public bool $tagged,
        public bool $latest,
    ) {
        $this->mirrorTag = $package . '/' . $tag;
    }
}
