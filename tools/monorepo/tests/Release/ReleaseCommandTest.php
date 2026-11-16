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

        $split = Splitter::split($repository->git, $head)['a'] ?? '';
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
        Assert::same($remotes->head('a', 'refs/tags/v0.1.0'), Splitter::split($repository->git, $head)['a'] ?? '');
    }

    public static function releasesNothingWhoseTagAndGitHubReleaseExist(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Change a', ['packages/a/src/A.php' => 'a2']);
        $api = new FakeApi([...self::SPLITS, 'repos/thesis-php/a/releases/tags/v0.1.0' => ['id' => 1]]);

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
            "a: v0.1.0 at %s\nSet SPLIT_REMOTE to release.\n",
            Splitter::split($repository->git, $head)['a'] ?? '',
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
        Assert::same($api->writes, ['POST repos/thesis-php/a/releases']);
    }

    /**
     * The tag was pushed by an earlier run, the package changed since: the GitHub Release is of the tag.
     */
    public static function continuesReleaseAtCommitOfItsTag(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        $split = self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Change a', ['packages/a/src/A.php' => 'a2']);
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($api->bodies['POST repos/thesis-php/a/releases']['target_commitish'] ?? null, $split);
    }

    /**
     * The GitHub Release of 0.1.0 failed, then 0.1.1 was released: a re-run of 0.1.0 still makes its release.
     */
    public static function continuesReleaseAfterLaterVersionWasTagged(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $first = $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $second = $repository->commit('Release a again', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => self::changelog('0.1.1', '0.1.0'),
        ]);
        self::tag($repository, $remotes, $second, 'a', 'v0.1.1');
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api, commit: $first);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($api->writes, ['POST repos/thesis-php/a/releases']);
    }

    public static function makesNoGitHubReleaseThatExists(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $head = $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $head, 'a', 'v0.1.0');
        $api = new FakeApi([...self::SPLITS, 'repos/thesis-php/a/releases/tags/v0.1.0' => ['id' => 1]]);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($api->writes, []);
    }

    public static function continuesReleaseWhoseTagHasNoV(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $head = $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $head, 'a', '0.1.0');
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($remotes->head('a', 'refs/tags/v0.1.0'), null);
        Assert::same($api->bodies['POST repos/thesis-php/a/releases']['tag_name'] ?? null, '0.1.0');
    }

    /**
     * The changelog of an older package of the same name ends with its last version, which it has tagged.
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

    public static function refusesVersionOfAnotherSeriesAndChangesUnderUnreleased(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $repository->commit('Release a', [
            'packages/a/CHANGELOG.md' => "## [Unreleased]\n\n- Not released.\n\n"
                . "## [0.2.0] - 2026-11-16\n\n- Released.\n",
        ]);

        [$code, $output] = self::run($repository, self::remotes(), new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert
            ::string($output)
            ->contains('a 0.2.0: [Unreleased] of packages/a/CHANGELOG.md lists changes that are not part of it.')
            ->contains('a 0.2.0: it is not of the series 0.1 of packages/a.')
        ;
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

    public static function refusesTagsAndBranchesOfOlderPackageOfSameName(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', self::package('a'));
        $repository->commit('Release a', ['packages/a/CHANGELOG.md' => self::changelog('0.1.0')]);
        $remotes = self::remotes();
        $older = new Repository();
        $older->commit('Older a', ['A.php' => 'older']);
        $older->git->run(['push', '--quiet', 'file://' . $remotes->dir . '/a.git', 'main:refs/tags/0.3.0']);
        $older->git->run(['push', '--quiet', 'file://' . $remotes->dir . '/a.git', 'main:refs/tags/0.0.9']);
        $older->git->run(['push', '--quiet', 'file://' . $remotes->dir . '/a.git', 'main:refs/heads/0.1.x']);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert
            ::string($output)
            ->contains('a 0.1.0: the tag 0.3.0 is not in the history of main of the split')
            ->notContains('the tag 0.0.9')
            ->contains('a 0.1.0: the branch 0.1.x of an older package collides with the series 0.1.')
        ;
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
     * A refused package keeps back only itself and its dependents: the others are released, the job fails.
     */
    public static function releasesPackagesIndependentOfRefusedOne(): void
    {
        $repository = new Repository();
        $repository->commit('Add a, b and c', [
            ...self::package('a'),
            ...self::package('b', require: ['thesis/a' => '^0.1'], released: ['0.1.0']),
            ...self::package('c', released: ['0.1.0']),
            'packages/a/CHANGELOG.md' => "## [Unreleased]\n\n- Not released.\n\n"
                . "## [0.1.0] - 2026-11-16\n\n- Released.\n",
        ]);
        $remotes = self::remotes();

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE, $output);
        Assert
            ::string($output)
            ->contains('a 0.1.0: [Unreleased] of packages/a/CHANGELOG.md lists changes that are not part of it.')
            ->contains('b 0.1.0: it requires thesis/a ^0.1, whose release 0.1.0 is refused.')
        ;
        Assert::same($remotes->head('a', 'refs/tags/v0.1.0'), null);
        Assert::same($remotes->head('b', 'refs/tags/v0.1.0'), null);
        Assert::notNull($remotes->head('c', 'refs/tags/v0.1.0'));
    }

    public static function releasesPackagesWhoseChangelogIsWellFormed(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b'),
            'packages/b/CHANGELOG.md' => "# Changelog\n\n## [0.1.0] - 2026-11-16\n",
        ]);
        $remotes = self::remotes();

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE, $output);
        Assert::string($output)->contains('packages/b/CHANGELOG.md: the first section must be ## [Unreleased].');
        Assert::notNull($remotes->head('a', 'refs/tags/v0.1.0'));
    }

    public static function releasesDependenciesFirst(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', [
            ...self::package('a'),
            ...self::package('b', require: ['thesis/a' => '^0.1']),
        ]);
        $repository->commit('Release a and b', [
            'packages/a/CHANGELOG.md' => self::changelog('0.1.0'),
            'packages/b/CHANGELOG.md' => self::changelog('0.1.0'),
        ]);
        $remotes = self::remotes();

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::SUCCESS, $output);
        Assert::true(strpos($output, 'a: tagged v0.1.0') < strpos($output, 'b: tagged v0.1.0'), $output);
        Assert::notNull($remotes->head('b', 'refs/tags/v0.1.0'));
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
     * A dependent would require a version that is not there, a package that does not depend on it is released.
     */
    public static function releasesNoDependentOfPackageThatFailed(): void
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
            ->contains('b: not released: it requires thesis/a, whose release failed.')
            ->contains('The release of a v0.1.0, b v0.1.0 failed.')
        ;
        Assert::notNull($remotes->head('a', 'refs/tags/v0.1.0'));
        Assert::same($remotes->head('b', 'refs/tags/v0.1.0'), null);
        Assert::notNull($remotes->head('c', 'refs/tags/v0.1.0'));
        Assert::same($api->writes, ['POST repos/thesis-php/a/releases', 'POST repos/thesis-php/c/releases']);
    }

    public static function refusesDependentOfDependencyThatChangedSinceItsTag(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a'),
            ...self::package('b', require: ['thesis/a' => '^0.1']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Change a', ['packages/a/src/A.php' => 'a2']);
        $repository->commit('Release b', ['packages/b/CHANGELOG.md' => self::changelog('0.1.0')]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains(
            'b 0.1.0: thesis/a changed since v0.1.0 in the files it ships: release a stable version of it too.',
        );
        Assert::same($remotes->head('b', 'refs/tags/v0.1.0'), null);
    }

    public static function releasesDependentOfDependencyThatChangedOnlyWhatItDoesNotShip(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a'),
            ...self::package('b', require: ['thesis/a' => '^0.1']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Test a', ['packages/a/tests/ATest.php' => 'test']);
        $repository->commit('Release b', ['packages/b/CHANGELOG.md' => self::changelog('0.1.0')]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::SUCCESS, $output);
        Assert::notNull($remotes->head('b', 'refs/tags/v0.1.0'));
    }

    /**
     * .gitattributes is not shipped itself, but what it keeps is the archive.
     */
    public static function refusesDependentOfDependencyThatStartedShippingPath(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a'),
            ...self::package('b', require: ['thesis/a' => '^0.1']),
            'packages/a/stubs/A.stub' => 'stub',
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Ship the stubs of a', [
            'packages/a/.gitattributes' => "/* export-ignore\n/composer.json -export-ignore\n/src -export-ignore\n"
                . "/stubs -export-ignore\n",
        ]);
        $repository->commit('Release b', ['packages/b/CHANGELOG.md' => self::changelog('0.1.0')]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains('b 0.1.0: thesis/a changed since v0.1.0 in the files it ships');
    }

    public static function refusesStableReleaseOnDependencyWithoutStableTag(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a'),
            ...self::package('b', require: ['thesis/a' => '^0.1']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0-beta1');
        $repository->commit('Release b', ['packages/b/CHANGELOG.md' => self::changelog('0.1.0')]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains(
            'b 0.1.0: it requires thesis/a ^0.1, which no stable tag meets: release a stable version of it too.',
        );
    }

    public static function refusesLowerBoundThatNoTagMeets(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a'),
            ...self::package('b', require: ['thesis/a' => '^0.1.3']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Release b', ['packages/b/CHANGELOG.md' => self::changelog('0.1.0-beta1')]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains(
            'b 0.1.0-beta1: it requires thesis/a ^0.1.3, which no existing tag meets: release a version of it too.',
        );
    }

    /**
     * A user of the stable dependent installs the stable tag of the dependency, not the beta the tests ran against.
     */
    public static function refusesStableDependentOfDependencyReleasedOnlyAsPreRelease(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.1']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Change a, release a beta and b', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => self::changelog('0.1.1-beta1', '0.1.0'),
            'packages/b/CHANGELOG.md' => self::changelog('0.1.0'),
        ]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE, $output);
        Assert::string($output)->contains('b 0.1.0: thesis/a changed since v0.1.0 in the files it ships');
        Assert::notNull($remotes->head('a', 'refs/tags/v0.1.1-beta1'));
        Assert::same($remotes->head('b', 'refs/tags/v0.1.0'), null);
    }

    public static function releasesPreReleaseDependentOfDependencyReleasedAsPreRelease(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.1']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Change a, release betas of a and b', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => self::changelog('0.1.1-beta1', '0.1.0'),
            'packages/b/CHANGELOG.md' => self::changelog('0.1.0-beta1'),
        ]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::SUCCESS, $output);
        Assert::notNull($remotes->head('b', 'refs/tags/v0.1.0-beta1'));
    }

    /**
     * A new series of a dependency starts a new series of the dependent: the older series stays installable
     * with the older dependency.
     */
    public static function refusesNewRangeOfDependencyInSameSeries(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a', released: ['0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.1'], released: ['0.1.0']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        self::tag($repository, $remotes, $first, 'b', 'v0.1.0');
        $repository->commit('Break a', [
            ...self::package('a', series: '0.2', source: 'a2', released: ['0.2.0', '0.1.0']),
            ...self::package('b', require: ['thesis/a' => '^0.2'], released: ['0.1.1', '0.1.0']),
        ]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains('b 0.1.1: it requires thesis/a ^0.2, v0.1.0 required ^0.1');
        Assert::notNull($remotes->head('a', 'refs/tags/v0.2.0'));
        Assert::same($remotes->head('b', 'refs/tags/v0.1.1'), null);
    }

    /**
     * A new minor of a dependency above 1.0 keeps its range: the dependent needs no new series.
     */
    public static function releasesPatchOverNewMinorOfDependencyWithSameRange(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a and b', [
            ...self::package('a', series: '2.0', released: ['2.0.0']),
            ...self::package('b', require: ['thesis/a' => '^2.0'], released: ['0.1.0']),
        ]);
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v2.0.0');
        self::tag($repository, $remotes, $first, 'b', 'v0.1.0');
        $repository->commit('Add to a, fix b', [
            ...self::package('a', series: '2.1', source: 'a2', released: ['2.1.0', '2.0.0']),
            ...self::package('b', require: ['thesis/a' => '^2.1'], source: 'b2', released: ['0.1.1', '0.1.0']),
        ]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::SUCCESS, $output);
        Assert::notNull($remotes->head('a', 'refs/tags/v2.1.0'));
        Assert::notNull($remotes->head('b', 'refs/tags/v0.1.1'));
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
    }

    /**
     * CI clones the monorepo: only the splits of the commits it computes are local. A tag of a later series
     * is at a split of a newer main, which a clone has only by splitting main.
     */
    public static function releasesVersionOfOlderCommitInCloneAfterHigherSeriesWasTagged(): void
    {
        $source = new Repository();
        $first = $source->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($source, $remotes, $first, 'a', 'v0.1.0');
        $fix = $source->commit('Fix a', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => self::changelog('0.1.1', '0.1.0'),
        ]);
        $break = $source->commit('Break a', [
            ...self::package('a', series: '0.2', source: 'a3', released: ['0.2.0', '0.1.1', '0.1.0']),
        ]);
        self::tag($source, $remotes, $break, 'a', 'v0.2.0');
        $clone = new Repository(cloneOf: $source);
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($clone, $remotes, $api, commit: $fix);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($remotes->head('a', 'refs/tags/v0.1.1'), Splitter::split($source->git, $fix)['a'] ?? '');
        Assert::same($api->bodies['POST repos/thesis-php/a/releases']['make_latest'] ?? null, 'false');
    }

    /**
     * actions/checkout of a push sets main and origin/main to the commit of the push, even when main has moved on.
     */
    public static function releasesVersionOfOlderPushInCloneWhoseMainIsAtItsCommit(): void
    {
        $source = new Repository();
        $first = $source->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($source, $remotes, $first, 'a', 'v0.1.0');
        $fix = $source->commit('Fix a', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => self::changelog('0.1.1', '0.1.0'),
        ]);
        $break = $source->commit('Break a', [
            ...self::package('a', series: '0.2', source: 'a3', released: ['0.2.0', '0.1.1', '0.1.0']),
        ]);
        self::tag($source, $remotes, $break, 'a', 'v0.2.0');
        $clone = new Repository(cloneOf: $source);
        $clone->git->run(['update-ref', 'refs/remotes/origin/main', $fix]);
        $clone->git->run(['checkout', '--quiet', '-B', 'main', $fix]);

        [$code, $output] = self::run($clone, $remotes, new FakeApi(self::SPLITS), commit: $fix);

        Assert::same($code, Command::SUCCESS, $output);
        Assert::same($remotes->head('a', 'refs/tags/v0.1.1'), Splitter::split($source->git, $fix)['a'] ?? '');
    }

    /**
     * A manifest that says 0.1 where 0.10 was meant would publish 0.1.0 below 0.9.0.
     */
    public static function refusesSeriesThatWentBack(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', series: '0.9', released: ['0.9.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.9.0');
        $repository->commit('Break a', [
            ...self::package('a', series: '0.1', source: 'a2', released: ['0.1.0', '0.9.0']),
        ]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains(
            'a 0.1.0: the tag v0.9.0 of a higher series is in the history of the split: the series 0.1 went back.',
        );
        Assert::same($remotes->head('a', 'refs/tags/v0.1.0'), null);
    }

    public static function refusesSeriesThatSkipsOne(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Break a', [
            ...self::package('a', series: '0.3', source: 'a2', released: ['0.3.0', '0.1.0']),
        ]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE);
        Assert::string($output)->contains(
            'a 0.3.0: the series 0.3 does not follow the series 0.1 of v0.1.0, expected 0.2 or 1.0.',
        );
    }

    public static function releasesNewSeriesThatFollowsLastOne(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Break a', [
            ...self::package('a', series: '0.2', source: 'a2', released: ['0.2.0', '0.1.0']),
        ]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::SUCCESS, $output);
        Assert::notNull($remotes->head('a', 'refs/tags/v0.2.0'));
    }

    /**
     * A change listed under the heading after an earlier run has tagged it is not in the tag.
     */
    public static function refusesNotesChangedSinceTag(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Fix a', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => "## [Unreleased]\n\n## [0.1.0] - 2026-11-16\n\n- Notes of 0.1.0.\n- A fix.\n",
        ]);
        $api = new FakeApi([...self::SPLITS, 'repos/thesis-php/a/releases/tags/v0.1.0' => ['id' => 1]]);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::FAILURE, $output);
        Assert::string($output)->contains(
            'a 0.1.0: its notes changed since the tag v0.1.0: list the changes after it under [Unreleased].',
        );
        Assert::same($api->writes, []);
    }

    /**
     * The GitHub Release of a tag has the notes the tag was made with.
     */
    public static function continuesReleaseWithNotesOfItsTag(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Fix a', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => "## [Unreleased]\n\n## [0.1.0] - 2026-11-16\n\n- Notes of 0.1.0.\n- A fix.\n",
        ]);
        $api = new FakeApi(self::SPLITS);

        [$code, $output] = self::run($repository, $remotes, $api);

        Assert::same($code, Command::FAILURE, $output);
        Assert::same($api->bodies['POST repos/thesis-php/a/releases']['body'] ?? null, '- Notes of 0.1.0.');
    }

    public static function releasesPackagesIndependentOfOneThatGitHubFailedToTell(): void
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
        Assert::string($output)->contains('a: GitHub is down.');
        Assert::notNull($remotes->head('c', 'refs/tags/v0.1.0'));
    }

    public static function releasesPackagesIndependentOfOneWithoutComposerJson(): void
    {
        $repository = new Repository();
        $files = self::package('a', released: ['0.1.0']);
        unset($files['packages/a/composer.json']);
        $repository->commit('Add a and c', [...$files, ...self::package('c', released: ['0.1.0'])]);
        $remotes = self::remotes();

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE, $output);
        Assert::string($output)->contains('a: no packages/a/composer.json at');
        Assert::notNull($remotes->head('c', 'refs/tags/v0.1.0'));
    }

    /**
     * A new series on top of a version whose push failed would leave that version unreleased for good.
     */
    public static function refusesVersionAboveOneWithoutTag(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $repository->commit('Fix a', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => self::changelog('0.1.1', '0.1.0'),
        ]);
        $repository->commit('Break a', [
            ...self::package('a', series: '0.2', source: 'a3', released: ['0.2.0', '0.1.1', '0.1.0']),
        ]);

        [$code, $output] = self::run($repository, $remotes, new FakeApi(self::SPLITS));

        Assert::same($code, Command::FAILURE, $output);
        Assert::string($output)->contains('a 0.2.0: 0.1.1 under it has no tag');
        Assert::same($remotes->head('a', 'refs/tags/v0.2.0'), null);
    }

    public static function reportsFailedListingOfRefsOnce(): void
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
    }

    /**
     * The run of an older push finds the tag that a later push made with the notes amended before the release.
     */
    public static function releasesNothingAtOlderCommitWhoseNotesLaterPushAmended(): void
    {
        $repository = new Repository();
        $first = $repository->commit('Add a', self::package('a', released: ['0.1.0']));
        $remotes = self::remotes();
        self::tag($repository, $remotes, $first, 'a', 'v0.1.0');
        $older = $repository->commit('Fix a', [
            'packages/a/src/A.php' => 'a2',
            'packages/a/CHANGELOG.md' => self::changelog('0.1.1', '0.1.0'),
        ]);
        $amended = str_replace('- Notes of 0.1.1.', '- Amended.', self::changelog('0.1.1', '0.1.0'));
        $later = $repository->commit('Amend the notes of a', ['packages/a/CHANGELOG.md' => $amended]);
        self::tag($repository, $remotes, $later, 'a', 'v0.1.1');
        $api = new FakeApi([...self::SPLITS, 'repos/thesis-php/a/releases/tags/v0.1.1' => ['id' => 1]]);

        [$code, $output] = self::run($repository, $remotes, $api, commit: $older);

        Assert::same([$code, $output], [Command::SUCCESS, "Nothing to release.\n"]);
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
            // Only composer.json and src/ are shipped.
            "packages/{$dir}/.gitattributes" => "/* export-ignore\n/composer.json -export-ignore\n"
                . "/src -export-ignore\n",
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
        $split = Splitter::split($repository->git, $commit)[$package] ?? '';
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
