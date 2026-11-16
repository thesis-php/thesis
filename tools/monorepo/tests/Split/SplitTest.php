<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Split;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Split\Split;

#[Test]
final readonly class SplitTest
{
    public static function splitsEveryPackageOfMain(): void
    {
        $repository = self::repository();

        $split = Split::of($repository->git);

        Assert::same(array_keys($split->commits), ['a', 'b']);
    }

    public static function splitsCommittedStateOnly(): void
    {
        $repository = self::repository();
        $before = Split::of($repository->git)->commits;
        file_put_contents($repository->dir . '/packages/a/A.php', 'staged');
        $repository->git->run(['add', '--all']);
        file_put_contents($repository->dir . '/packages/b/B.php', 'changed');
        mkdir($repository->dir . '/packages/c');
        file_put_contents($repository->dir . '/packages/c/C.php', 'untracked');

        Assert::same(Split::of($repository->git)->commits, $before);
    }

    public static function splitsMainWhateverIsCheckedOut(): void
    {
        $repository = self::repository();
        $main = Split::of($repository->git)->commits;
        $repository->git->run(['switch', '--quiet', '--create', 'other']);
        $repository->commit('Change a on other', ['packages/a/A.php' => 'other']);

        Assert::same(Split::of($repository->git)->commits, $main);
    }

    public static function failsWithoutMain(): void
    {
        $repository = self::repository();
        $repository->git->run(['branch', '--move', 'main', 'master']);

        Expect::exception(MonorepoException::class)->withMessage('refs/heads/main is not a ref of this repository.');

        Split::of($repository->git);
    }

    public static function failsOnMainWithoutPackages(): void
    {
        $repository = new Repository();
        $repository->commit('Add the readme', ['README.md' => 'readme']);

        Expect::exception(MonorepoException::class)->withMessage('refs/heads/main has no packages to split.');

        Split::of($repository->git);
    }

    private static function repository(): Repository
    {
        $repository = new Repository();
        $repository->commit('Add a and b', ['packages/a/A.php' => 'a1', 'packages/b/B.php' => 'b1']);

        return $repository;
    }
}
