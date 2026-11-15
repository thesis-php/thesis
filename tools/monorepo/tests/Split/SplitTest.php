<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Split;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Split\Split;

#[Test]
final readonly class SplitTest
{
    public static function pushesBranchToEveryPackage(): void
    {
        $repository = self::repository();

        $split = Split::of($repository->git, 'refs/heads/main');

        Assert::same(array_keys($split->commits), ['a', 'b']);
        Assert::same($split->unchanged, []);
        Assert::same($split->since, null);
    }

    public static function splitsCommittedStateOnly(): void
    {
        $repository = self::repository();
        $before = Split::of($repository->git, 'refs/heads/main')->commits;
        file_put_contents($repository->dir . '/packages/a/A.php', 'staged');
        $repository->git->run(['add', '--all']);
        file_put_contents($repository->dir . '/packages/b/B.php', 'changed');
        mkdir($repository->dir . '/packages/c');
        file_put_contents($repository->dir . '/packages/c/C.php', 'untracked');

        Assert::same(Split::of($repository->git, 'refs/heads/main')->commits, $before);
    }

    public static function pushesStableTagOnlyToPackagesChangedSincePreviousTagOfSeries(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'v2.1.0']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['tag', 'v2.1.1']);

        $split = Split::of($repository->git, 'refs/tags/v2.1.1');

        Assert::same(array_keys($split->commits), ['a']);
        Assert::same($split->unchanged, ['b']);
        Assert::same($split->since, 'v2.1.0');
    }

    public static function pushesTagToPackageAddedSincePreviousTag(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'v2.1.0']);
        $repository->commit('Add c', ['packages/c/C.php' => 'c1']);
        $repository->git->run(['tag', 'v2.1.1']);

        $split = Split::of($repository->git, 'refs/tags/v2.1.1');

        Assert::same(array_keys($split->commits), ['c']);
        Assert::same($split->unchanged, ['a', 'b']);
    }

    public static function comparesVersionsNotStrings(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'v2.9.0']);
        $repository->commit('Change b', ['packages/b/B.php' => 'b2']);
        $repository->git->run(['tag', 'v2.10.0']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['tag', 'v2.10.1']);

        $split = Split::of($repository->git, 'refs/tags/v2.10.1');

        Assert::same($split->since, 'v2.10.0');
        Assert::same(array_keys($split->commits), ['a']);
    }

    public static function takesPreviousTagFromHistoryOfTag(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'v2.1.0']);
        $repository->git->run(['switch', '--quiet', '--create', 'other']);
        $repository->commit('Change a on other', ['packages/a/A.php' => 'other']);
        $repository->git->run(['tag', 'v2.1.1']);
        $repository->git->run(['switch', '--quiet', 'main']);
        $repository->commit('Change b', ['packages/b/B.php' => 'b2']);
        $repository->git->run(['tag', 'v2.1.2']);

        $split = Split::of($repository->git, 'refs/tags/v2.1.2');

        Assert::same($split->since, 'v2.1.0');
        Assert::same(array_keys($split->commits), ['b']);
    }

    public static function takesPreviousTagLowerThanTag(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'v2.1.0']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['tag', 'v2.1.2']);
        $repository->git->run(['tag', 'v2.1.1']);

        $split = Split::of($repository->git, 'refs/tags/v2.1.1');

        Assert::same($split->since, 'v2.1.0');
    }

    public static function pushesFirstTagOfSeriesToEveryPackage(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'v2.0.3']);
        $repository->git->run(['tag', 'v2.1.0']);

        $split = Split::of($repository->git, 'refs/tags/v2.1.0');

        Assert::same(array_keys($split->commits), ['a', 'b']);
        Assert::same($split->since, null);
    }

    public static function pushesPreReleaseAndStableTagAfterItToEveryPackage(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'v2.0.0-alpha1']);
        $repository->git->run(['tag', 'v2.0.0-alpha2']);
        $repository->git->run(['tag', 'v2.0.0']);

        Assert::same(array_keys(Split::of($repository->git, 'refs/tags/v2.0.0-alpha2')->commits), ['a', 'b']);
        Assert::same(array_keys(Split::of($repository->git, 'refs/tags/v2.0.0')->commits), ['a', 'b']);
    }

    public static function splitsAnnotatedTag(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', '--annotate', '--message', 'Release', 'v2.1.0']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['tag', '--annotate', '--message', 'Release', 'v2.1.1']);

        $split = Split::of($repository->git, 'refs/tags/v2.1.1');

        Assert::same($split->commits, ['a' => Split::of($repository->git, 'refs/heads/main')->commits['a'] ?? '']);
    }

    public static function doesNotDependOnGitConfig(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'v2.1.0-beta1']);
        $repository->git->run(['tag', 'v2.1.0']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['tag', 'v2.1.1']);
        $repository->git->run(['config', 'column.ui', 'always']);
        $repository->git->run(['config', 'versionsort.suffix', '-beta']);
        $repository->git->run(['config', 'tag.sort', 'refname']);

        $split = Split::of($repository->git, 'refs/tags/v2.1.1');

        Assert::same($split->since, 'v2.1.0');
        Assert::same(array_keys($split->commits), ['a']);
    }

    #[DataSet(['refs/tags/latest'])]
    #[DataSet(['refs/remotes/origin/main'])]
    #[DataSet(['main'])]
    public static function failsOnRefThatIsNotBranchOrTag(string $ref): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'latest']);
        $repository->git->run(['update-ref', 'refs/remotes/origin/main', 'HEAD']);

        Expect::exception(MonorepoException::class)->withMessage(
            \sprintf('Only branches and v* tags are split, got %s.', $ref),
        );

        Split::of($repository->git, $ref);
    }

    #[DataSet(['refs/heads/missing'])]
    #[DataSet(['refs/heads/main~1'])]
    #[DataSet(['refs/heads/main@{0}'])]
    #[DataSet(['refs/tags/v2.1.0^{}'])]
    #[DataSet(['refs/heads/main:packages'])]
    public static function failsOnRefThatDoesNotExist(string $ref): void
    {
        $repository = self::repository();
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['tag', 'v2.1.0']);

        Expect::exception(MonorepoException::class)->withMessage(
            \sprintf('%s is not a ref of this repository.', $ref),
        );

        Split::of($repository->git, $ref);
    }

    #[DataSet(['v3'])]
    #[DataSet(['v2.1'])]
    #[DataSet(['v2.1.0RC1'])]
    #[DataSet(['v2.1.0-rc1'])]
    #[DataSet(['v02.1.0'])]
    #[DataSet(['v2.1.0-beta'])]
    public static function failsOnTagThatIsNotVersion(string $tag): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', $tag]);

        Expect::exception(MonorepoException::class)->withMessage(
            \sprintf('refs/tags/%s is not a version: tag vX.Y.Z or vX.Y.Z-alphaN, -betaN, -RCN.', $tag),
        );

        Split::of($repository->git, 'refs/tags/' . $tag);
    }

    public static function ignoresTagsThatAreNotVersions(): void
    {
        $repository = self::repository();
        $repository->git->run(['tag', 'v2.1.0']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['tag', 'v2.1']);
        $repository->git->run(['tag', 'v2.1.1']);

        Assert::same(Split::of($repository->git, 'refs/tags/v2.1.1')->since, 'v2.1.0');
    }

    public static function failsOnRefWithoutPackages(): void
    {
        $repository = new Repository();
        $repository->commit('Add the readme', ['README.md' => 'readme']);

        Expect::exception(MonorepoException::class)->withMessage('refs/heads/main has no packages to split.');

        Split::of($repository->git, 'refs/heads/main');
    }

    private static function repository(): Repository
    {
        $repository = new Repository();
        $repository->commit('Add a and b', ['packages/a/A.php' => 'a1', 'packages/b/B.php' => 'b1']);

        return $repository;
    }
}
