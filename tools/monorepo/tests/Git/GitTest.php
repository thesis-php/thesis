<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Git;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Tests\Split\Repository;

#[Test]
final readonly class GitTest
{
    public static function failsWithErrorOfGit(): void
    {
        $repository = new Repository();

        Expect::exception(MonorepoException::class)->withMessage(
            'git rev-parse --verify --quiet --end-of-options missing: exit code 1',
        );

        $repository->git->run(['rev-parse', '--verify', '--quiet', '--end-of-options', 'missing']);
    }

    public static function runsCommandsInParallelUnderTheirKeys(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1']);

        $results = $repository->git->runAll([
            'found' => ['rev-parse', 'HEAD'],
            'missing' => ['rev-parse', '--verify', '--quiet', 'missing'],
        ]);

        Assert::same(array_keys($results), ['found', 'missing']);
        Assert::same($results['found']->code ?? null, 0);
        Assert::same($results['found']->stdout ?? null, $repository->head() . "\n");
        Assert::same($results['missing']->code ?? null, 1);
    }

    /**
     * A hook or a parent git sets GIT_DIR, GIT_INDEX_FILE and the like: they would point the commands
     * at another repository.
     */
    public static function ignoresGitEnvironmentOfCaller(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1']);
        putenv('GIT_DIR=/nonexistent');

        try {
            $head = $repository->git->run(['rev-parse', 'HEAD']);
        } finally {
            putenv('GIT_DIR');
        }

        Assert::same($head, $repository->head() . "\n");
    }
}
