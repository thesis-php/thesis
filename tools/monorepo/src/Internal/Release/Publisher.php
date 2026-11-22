<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Internal\GitHub\Api;
use Thesis\Monorepo\Internal\GitHub\Repositories;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Split\Remote;

/**
 * Makes the steps of a release: the tag in the split repository, its GitHub Release, then the GitHub Release
 * of the monorepo that repeats it. A step that exists already counts as made, so a re-run continues a release
 * that failed halfway, and two runs may release the same headings at once.
 *
 * @internal
 */
final readonly class Publisher
{
    public const string MONOREPO = Repositories::ORGANIZATION . '/' . Repositories::MONOREPO;

    /**
     * @param non-empty-string $repository
     * @param non-empty-string $tag
     * @throws MonorepoException
     */
    public static function hasGitHubRelease(Api $api, string $repository, string $tag): bool
    {
        return $api->get(\sprintf('repos/%s/releases/tags/%s', $repository, rawurlencode($tag))) !== null;
    }

    public function __construct(
        private Git $git,
        private Remote $remote,
        private Api $api,
    ) {}

    /**
     * @return array{non-empty-string, non-empty-string} what was done: the tag, then the GitHub Release
     * @throws MonorepoException
     */
    public function publish(Release $release): array
    {
        return [$this->tag($release), $this->gitHubRelease($release)];
    }

    /**
     * Repeats the release in the monorepo: the tag <package>/vX.Y.Z at the commit that the split commit
     * was made from, and a GitHub Release of it. One request makes both: GitHub makes the tag, its commit
     * is in the monorepo already.
     *
     * @return array{non-empty-string} what was done
     * @throws MonorepoException
     */
    public function mirror(Release $release): array
    {
        if ($release->origin === null) {
            return [\sprintf(
                'no %s in the monorepo: %s is of a later commit, whose run makes it',
                $release->mirrorTag,
                $release->tag,
            )];
        }

        $repository = Repositories::ORGANIZATION . '/' . $release->package;
        $made = $this->make(
            repository: self::MONOREPO,
            tag: $release->mirrorTag,
            commit: $release->origin,
            name: \sprintf('%s/%s %s', Monorepo::VENDOR, $release->package, $release->version->toString()),
            // GitHub attaches the archives of the repository of a release, here of every package.
            notes: \sprintf(
                '> A copy of [%s %s](https://github.com/%s/releases/tag/%s): '
                    . "the archives below are of the whole monorepo.\n\n%s",
                $repository,
                $release->tag,
                $repository,
                rawurlencode($release->tag),
                $release->notes,
            ),
            release: $release,
        );

        return [$made
            ? \sprintf('released in the monorepo as %s at %s', $release->mirrorTag, $release->origin)
            : \sprintf('%s exists in the monorepo', $release->mirrorTag)];
    }

    /**
     * A lightweight tag at the split commit. Its push carries the commits, main of the split repository
     * is left to the split.
     *
     * @return non-empty-string
     */
    private function tag(Release $release): string
    {
        if ($release->tagged) {
            return \sprintf('the tag %s exists at %s', $release->tag, $release->commit);
        }

        $url = $this->remote->url($release->package);
        $ref = 'refs/tags/' . $release->tag;
        $git = $this->git->with($this->remote->env);
        // Never forced: a tag of another commit fails the release instead of moving a published version.
        $push = $git->result(['push', '--quiet', $url, $release->commit . ':' . $ref]);

        if ($push->code === 0) {
            return \sprintf('tagged %s at %s', $release->tag, $release->commit);
        }

        // Another run may have pushed the same tag meanwhile.
        $listed = $git->result(['ls-remote', $url, $ref]);

        if ($listed->code === 0 && str_starts_with($listed->stdout, $release->commit . "\t")) {
            return \sprintf('the tag %s exists at %s', $release->tag, $release->commit);
        }

        throw new MonorepoException(\sprintf('The push of %s failed: %s', $release->tag, trim($push->stderr)));
    }

    /**
     * @return non-empty-string
     */
    private function gitHubRelease(Release $release): string
    {
        $made = $this->make(
            repository: Repositories::ORGANIZATION . '/' . $release->package,
            tag: $release->tag,
            commit: $release->commit,
            name: $release->tag,
            notes: $release->notes,
            release: $release,
        );

        if (!$made) {
            return 'the GitHub Release exists';
        }

        return 'released on GitHub' . ($release->latest ? ' as the latest' : '');
    }

    /**
     * @param non-empty-string $repository
     * @param non-empty-string $tag
     * @param non-empty-string $commit the ID of the commit the release tag points to
     * @param non-empty-string $name
     * @return bool true if this call created the GitHub Release, or false if the release already exists
     */
    private function make(
        string $repository,
        string $tag,
        string $commit,
        string $name,
        string $notes,
        Release $release,
    ): bool {
        if (self::hasGitHubRelease($this->api, $repository, $tag)) {
            return false;
        }

        try {
            $this->api->send('POST', \sprintf('repos/%s/releases', $repository), [
                'tag_name' => $tag,
                // GitHub would tag the default branch otherwise.
                'target_commitish' => $commit,
                'name' => $name,
                'body' => $notes,
                'draft' => false,
                'prerelease' => $release->version->preRelease,
                // GitHub takes true when it is not sent.
                'make_latest' => $release->latest ? 'true' : 'false',
            ]);
        } catch (MonorepoException $exception) {
            if (self::hasGitHubRelease($this->api, $repository, $tag)) {
                return false;
            }

            throw $exception;
        }

        return true;
    }
}
