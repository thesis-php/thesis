<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Release;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\GitHub\Api;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Release\Publisher;
use Thesis\Monorepo\Internal\Release\Release;
use Thesis\Monorepo\Internal\Release\Version;
use Thesis\Monorepo\Internal\Split\Remote;
use Thesis\Monorepo\Tests\GitHub\FakeApi;
use Thesis\Monorepo\Tests\Split\Remotes;
use Thesis\Monorepo\Tests\Split\Repository;

/**
 * Two runs may release the same headings at once: what the other one made meanwhile counts as made.
 */
#[Test]
final readonly class PublisherTest
{
    public static function takesTagThatAnotherRunPushedAtSameCommit(): void
    {
        [$repository, $remotes, $commit] = self::setUp();
        $repository->git->run(['push', '--quiet', self::url($remotes), $commit . ':refs/tags/v0.1.0']);
        $api = new FakeApi();

        $steps = self::publisher($repository, $remotes, $api)->publish(self::release($commit));

        Assert::same($steps[0], \sprintf('tagged v0.1.0 at %s', $commit));
        Assert::same($remotes->head('a', 'refs/tags/v0.1.0'), $commit);
    }

    /**
     * The push of a run fails because the other run pushed the same tag first: the tag is listed at the commit.
     */
    public static function takesTagWhosePushFailedButWhichIsAtSameCommit(): void
    {
        [$repository, $remotes, $commit] = self::setUp();
        $repository->git->run(['push', '--quiet', self::url($remotes), $commit . ':refs/tags/v0.1.0']);
        // Every push goes to a repository that does not exist, ls-remote still reads the real one.
        $git = $repository->git->with([
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'url.file:///nonexistent/.pushInsteadOf',
            'GIT_CONFIG_VALUE_0' => 'file://' . $remotes->dir . '/',
        ]);
        $publisher = new Publisher($git, Remote::parse('file://' . $remotes->dir), new FakeApi());

        $steps = $publisher->publish(self::release($commit));

        Assert::same($steps[0], \sprintf('the tag v0.1.0 exists at %s', $commit));
    }

    public static function failsOnTagThatAnotherRunPushedAtAnotherCommit(): void
    {
        [$repository, $remotes, $commit] = self::setUp();
        $other = $repository->commit('Change', ['A.php' => 'a2']);
        $repository->git->run(['push', '--quiet', self::url($remotes), $other . ':refs/tags/v0.1.0']);

        Expect::exception(MonorepoException::class);

        try {
            self::publisher($repository, $remotes, new FakeApi())->publish(self::release($commit));
        } finally {
            Assert::same($remotes->head('a', 'refs/tags/v0.1.0'), $other);
        }
    }

    public static function takesGitHubReleaseThatAnotherRunMadeMeanwhile(): void
    {
        [$repository, $remotes, $commit] = self::setUp();
        $api = new class implements Api {
            private bool $created = false;

            #[\Override]
            public function get(string $path): ?array
            {
                return $this->created ? ['id' => 1] : null;
            }

            #[\Override]
            public function send(string $method, string $path, array $body): array
            {
                $this->created = true;

                throw new MonorepoException('GitHub: 422 already_exists');
            }
        };

        $steps = self::publisher($repository, $remotes, $api)->publish(self::release($commit));

        Assert::same($steps[1], 'the GitHub Release exists');
    }

    public static function failsWhenGitHubReleaseCannotBeMade(): void
    {
        [$repository, $remotes, $commit] = self::setUp();
        $api = new FakeApi(failingWrites: ['POST repos/thesis-php/a/releases' => 'GitHub is down.']);

        Expect::exception(MonorepoException::class)->withMessage('GitHub is down.');

        self::publisher($repository, $remotes, $api)->publish(self::release($commit));
    }

    public static function takesGitHubReleaseOfMonorepoThatExists(): void
    {
        [$repository, $remotes, $commit] = self::setUp();
        $api = new FakeApi(['repos/thesis-php/thesis/releases/tags/a%2Fv0.1.0' => ['id' => 1]]);

        $steps = self::publisher($repository, $remotes, $api)->mirror(self::release($commit));

        Assert::same($steps, ['a/v0.1.0 exists in the monorepo']);
        Assert::same($api->writes, []);
    }

    /**
     * @return array{Repository, Remotes, non-empty-string}
     */
    private static function setUp(): array
    {
        $repository = new Repository();
        $commit = $repository->commit('Add a', ['A.php' => 'a1']);
        $remotes = new Remotes();
        $remotes->git->run(['init', '--quiet', '--bare', 'a.git']);

        return [$repository, $remotes, $commit];
    }

    private static function publisher(Repository $repository, Remotes $remotes, Api $api): Publisher
    {
        return new Publisher($repository->git, Remote::parse('file://' . $remotes->dir), $api);
    }

    /**
     * @param non-empty-string $commit
     */
    private static function release(string $commit): Release
    {
        $version = Version::parse('0.1.0');
        \assert($version !== null);

        return new Release(
            package: 'a',
            version: $version,
            tag: 'v0.1.0',
            commit: $commit,
            origin: $commit,
            notes: '- Notes.',
            tagged: false,
            latest: true,
        );
    }

    private static function url(Remotes $remotes): string
    {
        return 'file://' . $remotes->dir . '/a.git';
    }
}
