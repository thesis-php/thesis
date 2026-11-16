<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Split;

use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Internal\GitHub\Repositories;
use Thesis\Monorepo\Internal\MonorepoException;

/**
 * What a push of main of the monorepo pushes to main of the split repositories: only main is split
 * (agents/versions.md), a version is a tag that the release pushes.
 *
 * @internal
 */
final readonly class Split
{
    public const string REF = 'refs/heads/' . Repositories::DEFAULT_BRANCH;

    public static function of(Git $git): self
    {
        if (!$git->succeeds(['show-ref', '--verify', '--quiet', self::REF])) {
            throw new MonorepoException(\sprintf('%s is not a ref of this repository.', self::REF));
        }

        // Resolved once: the ref may move while the split runs.
        $commit = trim($git->run(['rev-parse', '--verify', '--quiet', '--end-of-options', self::REF . '^{commit}']));
        \assert($commit !== '');
        $commits = Splitter::split($git, $commit);

        if ($commits === []) {
            throw new MonorepoException(\sprintf('%s has no packages to split.', self::REF));
        }

        return new self($commits);
    }

    /**
     * @param non-empty-array<string, non-empty-string> $commits package => split commit to push
     */
    private function __construct(
        public array $commits,
    ) {}
}
