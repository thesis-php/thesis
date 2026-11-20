<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Release;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Internal\Command\ReleaseCommand;
use Thesis\Monorepo\Internal\Json;
use Thesis\Monorepo\Internal\Split\Splitter;
use Thesis\Monorepo\Tests\Fixture;
use Thesis\Monorepo\Tests\GitHub\FakeApi;
use Thesis\Monorepo\Tests\Split\Remotes;
use Thesis\Monorepo\Tests\Split\Repository;

#[Test]
final readonly class ReleaseCommandTest
{
    /**
     * The repositories of a, b and c are splits: they exist under their names and have a ruleset of the split.
     */
    private const array SPLITS = [
        'repos/thesis-php/a' => ['full_name' => 'thesis-php/a'],
        'repos/thesis-php/a/rulesets' => [['id' => 1, 'name' => 'read-only branches']],
        'repos/thesis-php/b' => ['full_name' => 'thesis-php/b'],
        'repos/thesis-php/b/rulesets' => [['id' => 1, 'name' => 'read-only branches']],
        'repos/thesis-php/c' => ['full_name' => 'thesis-php/c'],
        'repos/thesis-php/c/rulesets' => [['id' => 1, 'name' => 'read-only branches']],
    ];

    public static function releasesHeadingWithoutTag(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $head = $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $remotes = self::remotes();
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api);

        $split = Splitter::split($repository->git, $head)->commits['a'] ?? '';
        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($remotes->head('a', 'refs/tags/v0.1.0'), $split);
        Assert::same($remotes->head('a', 'refs/heads/main'), null);
        Assert::same($api->bodies['POST repos/thesis-php/a/releases'] ?? null, [
            'tag_name' => 'v0.1.0',
            'target_commitish' => $split,
            'name' => 'v0.1.0',
            'body' => '- Notes of 0.1.0.',
            'draft' => false,
            'prerelease' => false,
            'make_latest' => 'true',
        ]);
        Assert::same($api->bodies['POST repos/thesis-php/thesis/releases'] ?? null, [
            'tag_name' => 'a/v0.1.0',
            'target_commitish' => $head,
            'name' => 'thesis/a 0.1.0',
            'body' => '> A copy of [thesis-php/a v0.1.0](https://github.com/thesis-php/a/releases/tag/v0.1.0): '
                . "the archives below are of the whole monorepo.\n\n- Notes of 0.1.0.",
            'draft' => false,
            'prerelease' => false,
            'make_latest' => 'true',
        ]);
    }

    /**
     * The tag of the monorepo is at the commit that made the split commit, whatever the head of the run is:
     * every run that repeats the release tags the same commit.
     */
    public static function repeatsReleaseInMonorepoAtCommitThatMadeItsSplit(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $release = $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $repository->commit('Change the root', ['README.md' => 'readme']);
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, self::remotes(), $api);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($api->bodies['POST repos/thesis-php/thesis/releases']['target_commitish'] ?? null, $release);
        Assert::string($output)->contains(\sprintf('a: released in the monorepo as a/v0.1.0 at %s', $release));
    }

    /**
     * A version released before the monorepo repeated the releases, or by a run that failed after its GitHub
     * Release: the next run repeats it.
     */
    public static function repeatsReleaseThatMonorepoLacks(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Change a', ['packages/a/src/A.php' => 'a2']);
        $api = new FakeApi([...self::SPLITS, 'repos/thesis-php/a/releases/tags/v0.1.0' => ['id' => 1]]);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($api->writes, ['POST repos/thesis-php/thesis/releases']);
        Assert::same($api->bodies['POST repos/thesis-php/thesis/releases']['target_commitish'] ?? null, $first);
    }

    /**
     * The checks of the push that added the heading failed: a later push releases it, as it is at its head.
     */
    public static function releasesHeadingThatEarlierPushDidNotRelease(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $head = $repository->commit('Fix a test of a', ['packages/a/tests/ATest.php' => 'fixed']);
        $remotes = self::remotes();

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same(
            actual: $remotes->head('a', 'refs/tags/v0.1.0'),
            expected: Splitter::split($repository->git, $head)->commits['a'] ?? '',
        );
    }

    public static function releasesNothingWhoseTagAndGitHubReleaseExist(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Change a', ['packages/a/src/A.php' => 'a2']);
        $api = new FakeApi([
            ...self::SPLITS,
            'repos/thesis-php/a/releases/tags/v0.1.0' => ['id' => 1],
            'repos/thesis-php/thesis/releases/tags/a%2Fv0.1.0' => ['id' => 2],
        ]);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same([$code, $output], [Command::SUCCESS, "Nothing to release.\n"]);
        Assert::same($api->writes, []);
    }

    public static function releasesNothingWithoutHeading(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, self::remotes(), $api);

        Assert::same([$code, $output], [Command::SUCCESS, "Nothing to release.\n"]);
        Assert::same($api->writes, []);
    }

    public static function onlyPrintsReleaseWithoutPush(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $head = $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $remotes = self::remotes();
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api, push: false);

        Assert::same($code, Command::SUCCESS);
        Assert::same($output, \sprintf(
            "a: v0.1.0 at %s, a/v0.1.0 of the monorepo at %s\nSet SPLIT_REMOTE to release.\n",
            Splitter::split($repository->git, $head)->commits['a'] ?? '',
            $head,
        ));
        Assert::same($remotes->head('a', 'refs/tags/v0.1.0'), null);
        Assert::same($api->writes, []);
    }

    /**
     * A release that failed halfway is continued: the tag counts as pushed, the GitHub Release is made.
     */
    public static function continuesReleaseWhoseTagExists(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $head = $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $remotes = self::remotes();
        $split = self::tag($repository, $remotes, $head, 'a', 'v0.1.0');
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::string($output)->contains(\sprintf('a: the tag v0.1.0 exists at %s', $split));
        Assert::same($api->writes, ['POST repos/thesis-php/a/releases', 'POST repos/thesis-php/thesis/releases']);
    }

    /**
     * A later push tagged the version and failed before its GitHub Release, then the job of an older push
     * is re-run: the GitHub Release is of the tag. CI clones the monorepo, and the commit of the tag
     * is of a later split, so it is not local: nothing reads it. The history of the older push does not tell
     * the commit of the monorepo that made it, so the monorepo is left to the run of the later push.
     */
    public static function continuesReleaseAtCommitOfItsTagThatIsNotLocal(): void
    {
        $source = new Repository();
        $source->commit('Add a', self::package('a', released: ['0.1.0']));
        $clone = new Repository(cloneOf: $source);
        $later = $source->commit('Change a', ['packages/a/src/A.php' => 'a2']);
        $remotes = self::remotes();
        $split = self::tag($source, $remotes, $later, 'a', 'v0.1.0');
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($clone, $remotes, $api);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($api->bodies['POST repos/thesis-php/a/releases']['target_commitish'] ?? null, $split);
        Assert::false($clone->git->succeeds(['cat-file', '-e', $split]));
        Assert::same($api->writes, ['POST repos/thesis-php/a/releases']);
        Assert::string($output)->contains(
            'a: no a/v0.1.0 in the monorepo: v0.1.0 is of a later commit, whose run makes it',
        );
    }

    /**
     * The run of an older push cannot tell whether the monorepo repeats a release of a later one, and does not ask.
     */
    public static function releasesNothingWhoseTagOfLaterCommitHasGitHubRelease(): void
    {
        $source = new Repository();
        $source->commit('Add a', self::package('a', released: ['0.1.0']));
        $clone = new Repository(cloneOf: $source);
        $later = $source->commit('Change a', ['packages/a/src/A.php' => 'a2']);
        $remotes = self::remotes();
        self::tag($source, $remotes, $later, 'a', 'v0.1.0');
        $api = new FakeApi([...self::SPLITS, 'repos/thesis-php/a/releases/tags/v0.1.0' => ['id' => 1]]);

        [$code, $output] = self::run($clone, $remotes, $api);

        Assert::same([$code, $output], [Command::SUCCESS, "Nothing to release.\n"]);
    }

    /**
     * The release writes vX.Y.Z: a tag without v is of an older package of the same name, whose changelog
     * ends with its last version.
     */
    public static function releasesNothingThatOlderPackageOfSameNameTagged(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        $older = new Repository();
        $older->commit('Older a', ['A.php' => 'older']);
        $older->git->run(['push', '--quiet', 'file://' . $remotes->dir . '/a.git', 'main:refs/tags/0.1.0']);
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same([$code, $output], [Command::SUCCESS, "Nothing to release.\n"]);
        Assert::same($remotes->head('a', 'refs/tags/v0.1.0'), null);
        Assert::same($api->writes, []);
    }

    public static function refusesVersionThatDoesNotComeRightAfterLastTag(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.10', '0.1.0')]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains(
            'a 0.1.10: it does not come right after v0.1.0, expected 0.1.1, 0.1.1-alpha1, 0.1.1-beta1, 0.1.1-RC1.',
        );
        Assert::same($remotes->head('a', 'refs/tags/v0.1.10'), null);
    }

    /**
     * The last tag of a series may be a pre-release: what comes after it is its next pre-release or its stable.
     */
    public static function refusesVersionThatDoesNotComeRightAfterLastPreRelease(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0-beta1']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0-beta1');
        $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.1', '0.1.0-beta1')]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains(
            'a 0.1.1: it does not come right after v0.1.0-beta1, expected 0.1.0, 0.1.0-beta2, 0.1.0-RC1.',
        );
        Assert::same($remotes->head('a', 'refs/tags/v0.1.1'), null);
    }

    public static function refusesVersionOfAnotherSeriesAndChangesUnderUnreleased(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::withUnreleased(self::changelog('0.2.0'))]);

        [$code, $output] = self::run($repository, self::remotes(), new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert
            ::string($output)
            ->contains('a 0.2.0: [Unreleased] of packages/a/CHANGELOG.md lists changes that are not part of it.')
            ->contains('a 0.2.0: it is not of the series 0.1 of packages/a.')
        ;
    }

    public static function releasesNewSeries(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Break a', self::package('a', series: '0.2', source: 'a2', released: ['0.2.0', '0.1.0']));

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::SUCCESS, $output);
        Assert::notNull($remotes->head('a', 'refs/tags/v0.2.0'));
    }

    public static function refusesHeadingThatIsNotOfRelease(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', [
            ...self::package('a'),
            'packages/a/CHANGELOG.md' => "## [Unreleased]\n\n## [0.1.0]\n\n- Released.\n",
        ]);

        [$code, $output] = self::run($repository, self::remotes(), new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains('packages/a/CHANGELOG.md: [0.1.0] is not a heading of a release');
    }

    public static function refusesRepositoryThatIsNotSplit(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $api = new FakeApi([...self::SPLITS, 'repos/thesis-php/a/rulesets' => []]);

        [$code, $output] = self::run($repository, self::remotes(), $api);

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains('a 0.1.0: thesis-php/a is not a split');
    }

    public static function refusesPackageWithoutComposerJson(): void
    {
        $repository = new Repository();
        $files = self::package('a', released: ['0.1.0']);
        unset($files['packages/a/composer.json']);
        $repository->commit('Add a', $files);

        [$code, $output] = self::run($repository, self::remotes(), new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE, $output);
        Assert::string($output)->contains('a 0.1.0: no packages/a/composer.json.');
    }

    public static function refusesCommitThatIsNotOfMain(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $repository->git->run(['switch', '--quiet', '--create', 'other']);
        $commit = $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);

        [$code, $output] = self::run($repository, self::remotes(), new FakeApi(self::SPLITS), commit: $commit);

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains(\sprintf('%s is not reachable from refs/heads/main.', $commit));
    }

    /**
     * Everything is checked before anything is pushed: one refused package releases none, with every reason.
     */
    public static function releasesNothingWhenOnePackageIsRefused(): void
    {
        $repository = new Repository();
        $repository->commit('Add a, b and c', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b', released: ['0.1.0']),
            ...self::package('c', released: ['0.1.0']),
            'packages/a/CHANGELOG.md' => self::withUnreleased(self::changelog('0.1.0')),
            'packages/b/CHANGELOG.md' => "# Changelog\n\n## [0.1.0] - 2026-11-16\n",
        ]);
        $remotes = self::remotes();
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::FAILURE, $output);
        Assert
            ::string($output)
            ->contains('a 0.1.0: [Unreleased] of packages/a/CHANGELOG.md lists changes that are not part of it.')
            ->contains('packages/b/CHANGELOG.md: the first section must be ## [Unreleased].')
            ->contains('Nothing is released.')
        ;
        Assert::same($remotes->head('c', 'refs/tags/v0.1.0'), null);
        Assert::same($api->writes, []);
    }

    public static function releasesNothingWhenGitHubFailsToTell(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and c', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('c', released: ['0.1.0']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $api = new FakeApi(self::SPLITS, failing: ['repos/thesis-php/a/releases/tags/v0.1.0' => 'GitHub is down.']);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::FAILURE, $output);
        Assert::string($output)->contains('a 0.1.0: GitHub is down.');
        Assert::same($remotes->head('c', 'refs/tags/v0.1.0'), null);
    }

    public static function reportsFailedListingOfTagsOnce(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.1'], released: ['0.1.0']),
        ]);
        $remotes = self::remotes();
        Fixture::remove($remotes->dir . '/a.git');

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE, $output);
        Assert::same(substr_count($output, 'thesis-php/a: git ls-remote'), 1, $output);
        Assert::same($remotes->head('b', 'refs/tags/v0.1.0'), null);
    }

    public static function releasesDependenciesFirst(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', [
            ...self::package('a', require: ['thesis/b' => '^0.1']),
            ...self::package('b'),
        ]);
        $repository->commit('Release a and b', [
            'packages/a/CHANGELOG.md' => self::changelog('0.1.0'),
            'packages/b/CHANGELOG.md' => self::changelog('0.1.0'),
        ]);
        $remotes = self::remotes();

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::SUCCESS, $output);
        Assert::true(strpos($output, 'b: tagged v0.1.0') < strpos($output, 'a: tagged v0.1.0'), $output);
        Assert::notNull($remotes->head('a', 'refs/tags/v0.1.0'));
    }

    public static function ordersByMonorepoPackagesOnly(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', [
            ...self::package('a', require: ['league/b' => '^1.0'], released: ['0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.1'], released: ['0.1.0']),
        ]);

        [$code, $output] = self::run($repository, self::remotes(), new FakeApi(self::SPLITS));

        Assert::same($code, Command::SUCCESS, $output);
        Assert::true(strpos($output, 'a: tagged v0.1.0') < strpos($output, 'b: tagged v0.1.0'), $output);
    }

    /**
     * A dependent would require a version that is not there: what comes after a failure is left to a re-run.
     */
    public static function stopsAtReleaseThatFailed(): void
    {
        $repository = new Repository();
        $repository->commit('Add a, b and c', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.1'], released: ['0.1.0']),
            ...self::package('c', released: ['0.1.0']),
        ]);
        $remotes = self::remotes();
        $api = new FakeApi(self::SPLITS, failingWrites: ['POST repos/thesis-php/a/releases' => 'GitHub is down.']);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::FAILURE);
        Assert
            ::string($output)
            ->contains('a: GitHub is down.')
            ->contains('The release stopped at a v0.1.0.')
        ;
        Assert::notNull($remotes->head('a', 'refs/tags/v0.1.0'));
        Assert::same($remotes->head('b', 'refs/tags/v0.1.0'), null);
        Assert::same($remotes->head('c', 'refs/tags/v0.1.0'), null);
        Assert::same($api->writes, ['POST repos/thesis-php/a/releases']);
    }

    /**
     * The monorepo only repeats the releases: one it failed to repeat holds back no other.
     */
    public static function repeatsReleasesInMonorepoAfterAllOfThem(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.1'], released: ['0.1.0']),
        ]);
        $remotes = self::remotes();
        $api = new FakeApi(self::SPLITS, failingWrites: ['POST repos/thesis-php/thesis/releases' => 'GitHub is down.']);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::FAILURE);
        Assert
            ::string($output)
            ->contains('a: GitHub is down.')
            ->contains('The release stopped at a v0.1.0.')
        ;
        Assert::same($api->writes, [
            'POST repos/thesis-php/a/releases',
            'POST repos/thesis-php/b/releases',
            'POST repos/thesis-php/thesis/releases',
        ]);
    }

    public static function refusesDependentOfDependencyWithChangesUnderUnreleased(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.1']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Change a, release b', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => self::withUnreleased(self::changelog('0.1.0')),
            'packages/b/CHANGELOG.md' => self::changelog('0.1.0'),
        ]);
        $api = new FakeApi([...self::SPLITS, 'repos/thesis-php/a/releases/tags/v0.1.0' => ['id' => 1]]);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains(
            'b 0.1.0: it requires thesis/a, whose [Unreleased] lists changes: release it too.',
        );
        Assert::same($remotes->head('b', 'refs/tags/v0.1.0'), null);
    }

    public static function releasesDependentOfDependencyWithNothingUnderUnreleased(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.1']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Test a, release b', [
            'packages/a/tests/ATest.php' => 'test',
            'packages/b/CHANGELOG.md' => self::changelog('0.1.0'),
        ]);
        $api = new FakeApi([...self::SPLITS, 'repos/thesis-php/a/releases/tags/v0.1.0' => ['id' => 1]]);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::notNull($remotes->head('b', 'refs/tags/v0.1.0'));
    }

    public static function releasesPreReleaseThatIsNotLatest(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0-beta1')]);
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, self::remotes(), $api);

        Assert::same($code, Command::SUCCESS, $output);
        $body = $api->bodies['POST repos/thesis-php/a/releases'] ?? [];
        Assert::same([$body['prerelease'] ?? null, $body['make_latest'] ?? null], [true, 'false']);
        $mirror = $api->bodies['POST repos/thesis-php/thesis/releases'] ?? [];
        Assert::same([$mirror['prerelease'] ?? null, $mirror['make_latest'] ?? null], [true, 'false']);
    }

    /**
     * The run of an older push releases its version after a later push has tagged a higher series.
     */
    public static function releasesVersionBelowHighestOneNotAsLatest(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $fix = $repository->commit('Fix a', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => self::changelog('0.1.1', '0.1.0'),
        ]);
        $files = self::package('a', series: '0.2', source: 'a3', released: ['0.2.0', '0.1.1', '0.1.0']);
        $break = $repository->commit('Break a', $files);
        self::tag($repository, $remotes, $break, 'a', 'v0.2.0');
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api, commit: $fix);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same(
            actual: $remotes->head('a', 'refs/tags/v0.1.1'),
            expected: Splitter::split($repository->git, $fix)->commits['a'] ?? '',
        );
        Assert::same($api->bodies['POST repos/thesis-php/a/releases']['make_latest'] ?? null, 'false');
    }

    /**
     * @return array{int, string} the exit code and the output
     */
    private static function run(
        Repository $repository,
        Remotes $remotes,
        FakeApi $api,
        string $commit = 'refs/heads/main',
        bool $push = true,
    ): array {
        $application = new Application();
        $application->addCommand(new ReleaseCommand(
            root: $repository->dir,
            remote: 'file://' . $remotes->dir,
            push: $push,
            api: $api,
        ));
        $tester = new CommandTester($application->find('release'));
        $code = $tester->execute(['commit' => $commit], ['decorated' => false]);

        return [$code, $tester->getDisplay()];
    }

    /**
     * @param array<string, string> $require
     * @param list<string> $released the versions in the changelog, the newest first
     * @return array<string, string> the files of a package as sync generates them
     */
    private static function package(
        string $dir,
        string $series = '0.1',
        array $require = [],
        string $source = '',
        array $released = [],
    ): array {
        return [
            "packages/{$dir}/composer.json" => Json::encode([
                'name' => 'thesis/' . $dir,
                'require' => ['php' => '^8.5', ...$require],
                'extra' => ['branch-alias' => ['dev-main' => $series . '.x-dev']],
            ]),
            "packages/{$dir}/src/A.php" => $source === '' ? $dir . '1' : $source,
            "packages/{$dir}/CHANGELOG.md" => self::changelog(...$released),
        ];
    }

    private static function changelog(string ...$versions): string
    {
        $changelog = "# Changelog\n\n## [Unreleased]\n";

        foreach ($versions as $version) {
            $changelog .= "\n## [{$version}] - 2026-11-16\n\n- Notes of {$version}.\n";
        }

        return $changelog;
    }

    private static function withUnreleased(string $changelog): string
    {
        return str_replace("## [Unreleased]\n", "## [Unreleased]\n\n- Not released.\n", $changelog);
    }

    /**
     * Pushes a tag of the split of a package at a commit, as an earlier release did.
     *
     * @param non-empty-string $commit
     * @return non-empty-string the split commit
     */
    private static function tag(
        Repository $repository,
        Remotes $remotes,
        string $commit,
        string $package,
        string $tag,
    ): string {
        $split = Splitter::split($repository->git, $commit)->commits[$package] ?? '';
        \assert($split !== '');
        $url = 'file://' . $remotes->dir . '/' . $package . '.git';
        $repository->git->run(['push', '--quiet', $url, $split . ':refs/tags/' . $tag]);

        return $split;
    }

    private static function remotes(): Remotes
    {
        $remotes = new Remotes();

        foreach (['a', 'b', 'c'] as $package) {
            $remotes->git->run(['init', '--quiet', '--bare', $package . '.git']);
        }

        return $remotes;
    }
}
