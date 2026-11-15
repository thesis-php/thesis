<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Split;

use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;

/**
 * What a push of a branch or a v* tag of the monorepo pushes to the split repositories.
 *
 * @internal
 */
final readonly class Split
{
    private const string BRANCHES = 'refs/heads/';
    private const string TAGS = 'refs/tags/';

    /**
     * A stable tag goes only to the packages that changed since the previous stable tag of the same minor series.
     * Every other tag goes to all packages: the first tag of a series (v2.1.0), so that ^2.1 resolves for each
     * of them, and a pre-release or the first stable tag after one (v2.0.0-beta1, v2.0.0), so that no package
     * is left with a less stable version than the others.
     */
    public static function of(Git $git, string $ref): self
    {
        if (!str_starts_with($ref, self::BRANCHES) && !str_starts_with($ref, self::TAGS . 'v')) {
            throw new MonorepoException(\sprintf('Only branches and v* tags are split, got %s.', $ref));
        }

        // A full name of a ref only: a revision like main~1 would push another commit under this name.
        if (!$git->succeeds(['show-ref', '--verify', '--quiet', $ref])) {
            throw new MonorepoException(\sprintf('%s is not a ref of this repository.', $ref));
        }

        // Resolved once: the ref may move while the split runs.
        $commit = self::commit($git, $ref);
        $since = str_starts_with($ref, self::TAGS) ? self::previousTag($git, $commit, self::version($ref)) : null;

        $commits = Splitter::split($git, $commit);

        if ($commits === []) {
            throw new MonorepoException(\sprintf('%s has no packages to split.', $ref));
        }

        $unchanged = [];

        if ($since !== null) {
            $catFile = $git->catFile();
            $before = $catFile->subtrees(self::commit($git, self::TAGS . $since) . ':' . Monorepo::PACKAGES_DIR);
            $now = $catFile->subtrees($commit . ':' . Monorepo::PACKAGES_DIR);

            foreach (array_keys($commits) as $package) {
                if (($before[$package] ?? null) === ($now[$package] ?? null)) {
                    $unchanged[] = $package;
                    unset($commits[$package]);
                }
            }
        }

        return new self(
            ref: $ref,
            commits: $commits,
            unchanged: $unchanged,
            since: $since,
        );
    }

    /**
     * @param non-empty-string $ref
     * @param array<string, non-empty-string> $commits package => split commit to push
     * @param list<string> $unchanged packages that do not get the tag
     * @param ?string $since the previous tag that the unchanged packages are compared with
     */
    private function __construct(
        public string $ref,
        public array $commits,
        public array $unchanged,
        public ?string $since,
    ) {}

    private static function version(string $ref): Version
    {
        return Version::parse(substr($ref, \strlen(self::TAGS))) ?? throw new MonorepoException(\sprintf(
            '%s is not a version: tag vX.Y.Z or vX.Y.Z-alphaN, -betaN, -RCN.',
            $ref,
        ));
    }

    /**
     * @return non-empty-string
     */
    private static function commit(Git $git, string $ref): string
    {
        $commit = trim($git->run(['rev-parse', '--verify', '--quiet', '--end-of-options', $ref . '^{commit}']));
        \assert($commit !== '');

        return $commit;
    }

    /**
     * The highest version below this one among the tags of its history, if it is a stable one of the same series.
     * Versions are compared here, not by `git tag --sort`: its order depends on the config of the user.
     */
    private static function previousTag(Git $git, string $commit, Version $version): ?string
    {
        $tags = $git->run(['for-each-ref', '--merged=' . $commit, '--format=%(refname)', self::TAGS . 'v*']);
        $previous = null;

        foreach (explode("\n", trim($tags)) as $tag) {
            $candidate = Version::parse(substr($tag, \strlen(self::TAGS)));

            if ($candidate !== null
                && $candidate->isLowerThan($version)
                && ($previous === null || $previous->isLowerThan($candidate))
            ) {
                $previous = $candidate;
            }
        }

        if ($previous === null || $previous->preRelease || $version->preRelease || !$previous->isOfSeries($version)) {
            return null;
        }

        return $previous->tag;
    }
}
