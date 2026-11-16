<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Release;

use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Internal\GitHub\Api;
use Thesis\Monorepo\Internal\GitHub\Repositories;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Split\Remote;

/**
 * Makes the steps of a release: the tag in the split repository, then its GitHub Release. A step that exists
 * already counts as made, so a re-run continues a release that failed halfway, and two runs may release
 * the same headings at once.
 *
 * @internal
 */
final readonly class Publisher
{
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
        $repository = Repositories::ORGANIZATION . '/' . $release->package;
        $existing = \sprintf('repos/%s/releases/tags/%s', $repository, rawurlencode($release->tag));

        if ($this->api->get($existing) !== null) {
            return 'the GitHub Release exists';
        }

        try {
            $this->api->send('POST', \sprintf('repos/%s/releases', $repository), [
                'tag_name' => $release->tag,
                // GitHub would tag the default branch otherwise.
                'target_commitish' => $release->commit,
                'name' => $release->tag,
                'body' => $release->notes,
                'draft' => false,
                'prerelease' => $release->version->preRelease,
                // GitHub takes true when it is not sent.
                'make_latest' => $release->latest ? 'true' : 'false',
            ]);
        } catch (MonorepoException $exception) {
            if ($this->api->get($existing) !== null) {
                return 'the GitHub Release exists';
            }

            throw $exception;
        }

        return 'released on GitHub' . ($release->latest ? ' as the latest' : '');
    }
}
