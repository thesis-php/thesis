<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Split;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Internal\Command\SplitCommand;
use Thesis\Monorepo\Internal\Split\Split;
use Thesis\Monorepo\Tests\GitHub\FakeApi;

#[Test]
final readonly class SplitCommandTest
{
    /**
     * The repositories of a and b are splits: they exist under their names and have a ruleset of the split.
     */
    private const array SPLITS = [
        'repos/thesis-php/a' => ['full_name' => 'thesis-php/a'],
        'repos/thesis-php/a/rulesets' => [['id' => 1, 'name' => 'read-only branches']],
        'repos/thesis-php/b' => ['full_name' => 'thesis-php/b'],
        'repos/thesis-php/b/rulesets' => [['id' => 1, 'name' => 'read-only branches']],
    ];

    public static function printsSplitsWithoutRemote(): void
    {
        $repository = self::repository();
        $commits = Split::of($repository->git, 'refs/heads/main')->commits;

        [$code, $output] = self::run($repository, 'refs/heads/main', remote: null);

        Assert::same($code, Command::SUCCESS);
        Assert::same($output, \sprintf("a: %s\nb: %s\n", $commits['a'] ?? '', $commits['b'] ?? ''));
    }

    public static function pushesEveryPackageToItsRepository(): void
    {
        $repository = self::repository();
        $remotes = self::remotes('a', 'b');
        $commits = Split::of($repository->git, 'refs/heads/main')->commits;

        [$code] = self::run($repository, 'refs/heads/main', remote: 'file://' . $remotes->dir);
        [$again] = self::run($repository, 'refs/heads/main', remote: 'file://' . $remotes->dir);

        Assert::same([$code, $again], [Command::SUCCESS, Command::SUCCESS]);
        Assert::same($remotes->head('a', 'refs/heads/main'), $commits['a'] ?? '');
        Assert::same($remotes->head('b', 'refs/heads/main'), $commits['b'] ?? '');
    }

    public static function pushesTagOnlyToChangedPackages(): void
    {
        $repository = self::repository();
        $remotes = self::remotes('a', 'b');
        $repository->git->run(['tag', 'v2.1.0']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['tag', 'v2.1.1']);

        [$code, $output] = self::run($repository, 'refs/tags/v2.1.1', remote: 'file://' . $remotes->dir);

        Assert::same($code, Command::SUCCESS);
        Assert::true(str_starts_with($output, "b: unchanged since v2.1.0, skipped\n"));
        Assert::same(
            actual: $remotes->head('a', 'refs/tags/v2.1.1'),
            expected: Split::of($repository->git, 'refs/tags/v2.1.1')->commits['a'] ?? '',
        );
        Assert::same($remotes->head('b', 'refs/tags/v2.1.1'), null);
    }

    /**
     * A push that cannot fast-forward fails instead of rewriting the published history.
     */
    public static function failsOnPushThatIsNotFastForwardAndPushesTheOthers(): void
    {
        $repository = self::repository();
        $remotes = self::remotes('a', 'b');
        $other = new Repository();
        $other->commit('Unrelated', ['B.php' => 'other']);
        $other->git->run(['push', '--quiet', 'file://' . $remotes->dir . '/b.git', 'main']);

        [$code, $output] = self::run($repository, 'refs/heads/main', remote: 'file://' . $remotes->dir);

        Assert::same($code, Command::FAILURE);
        Assert::true(str_contains($output, 'The push of b failed.'));
        Assert::same(
            actual: $remotes->head('a', 'refs/heads/main'),
            expected: Split::of($repository->git, 'refs/heads/main')->commits['a'] ?? '',
        );
        Assert::same($remotes->head('b', 'refs/heads/main'), $other->head());
    }

    /**
     * An older package of the same name has no main, so the push would create it instead of failing.
     */
    public static function skipsRepositoryWithoutRulesetsAndPushesTheOthers(): void
    {
        $repository = self::repository();
        $remotes = self::remotes('a', 'b');
        $other = new Repository();
        $other->commit('Older b', ['B.php' => 'other']);
        $other->git->run(['push', '--quiet', 'file://' . $remotes->dir . '/b.git', 'main:1.0.x']);

        [$code, $output] = self::run(
            repository: $repository,
            ref: 'refs/heads/main',
            remote: 'file://' . $remotes->dir,
            api: new FakeApi([...self::SPLITS, 'repos/thesis-php/b/rulesets' => []]),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(str_contains($output, 'b: thesis-php/b is not a split of packages/b'));
        Assert::true(str_contains($output, 'The push of b failed.'));
        Assert::same(
            actual: $remotes->head('a', 'refs/heads/main'),
            expected: Split::of($repository->git, 'refs/heads/main')->commits['a'] ?? '',
        );
        Assert::same($remotes->head('b', 'refs/heads/main'), null);
    }

    /**
     * GitHub redirects the old name of a renamed repository, the API and git push alike:
     * the rulesets would be those of the other repository, and so would the push.
     */
    public static function skipsNameThatRedirectsToRenamedRepository(): void
    {
        $repository = self::repository();
        $remotes = self::remotes('a', 'b');

        [$code, $output] = self::run(
            repository: $repository,
            ref: 'refs/heads/main',
            remote: 'file://' . $remotes->dir,
            api: new FakeApi([...self::SPLITS, 'repos/thesis-php/b' => ['full_name' => 'thesis-php/b-renamed']]),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(str_contains($output, 'b: thesis-php/b is not a split of packages/b'));
        Assert::same($remotes->head('b', 'refs/heads/main'), null);
    }

    public static function failsOnApiErrorAndPushesTheOthers(): void
    {
        $repository = self::repository();
        $remotes = self::remotes('a', 'b');

        [$code, $output] = self::run(
            repository: $repository,
            ref: 'refs/heads/main',
            remote: 'file://' . $remotes->dir,
            api: new FakeApi(self::SPLITS, failing: ['repos/thesis-php/b/rulesets' => 'GitHub is down.']),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(str_contains($output, 'b: GitHub is down.'));
        Assert::same(
            actual: $remotes->head('a', 'refs/heads/main'),
            expected: Split::of($repository->git, 'refs/heads/main')->commits['a'] ?? '',
        );
        Assert::same($remotes->head('b', 'refs/heads/main'), null);
    }

    public static function skipsRepositoryThatIsNotSplitForTag(): void
    {
        $repository = self::repository();
        $remotes = self::remotes('a', 'b');
        $repository->git->run(['tag', 'v2.1.0']);

        [$code] = self::run(
            repository: $repository,
            ref: 'refs/tags/v2.1.0',
            remote: 'file://' . $remotes->dir,
            api: new FakeApi([...self::SPLITS, 'repos/thesis-php/b/rulesets' => []]),
        );

        Assert::same($code, Command::FAILURE);
        Assert::same(
            actual: $remotes->head('a', 'refs/tags/v2.1.0'),
            expected: Split::of($repository->git, 'refs/tags/v2.1.0')->commits['a'] ?? '',
        );
        Assert::same($remotes->head('b', 'refs/tags/v2.1.0'), null);
    }

    public static function failsWithoutApiWhenPushing(): void
    {
        $repository = self::repository();
        $remotes = self::remotes('a', 'b');

        [$code, $output] = self::run($repository, 'refs/heads/main', remote: 'file://' . $remotes->dir, api: null);

        Assert::same($code, Command::FAILURE);
        Assert::true(str_contains($output, 'Set GITHUB_TOKEN'));
        Assert::same($remotes->head('a', 'refs/heads/main'), null);
    }

    public static function failsOnInvalidRefWithoutPushing(): void
    {
        $repository = self::repository();
        $remotes = self::remotes('a', 'b');

        [$code, $output] = self::run($repository, 'refs/heads/missing', remote: 'file://' . $remotes->dir);

        Assert::same($code, Command::FAILURE);
        Assert::true(str_contains($output, 'refs/heads/missing is not a ref of this repository.'));
        Assert::same($remotes->head('a', 'refs/heads/main'), null);
    }

    /**
     * @return array{int, string} the exit code and the output
     */
    private static function run(
        Repository $repository,
        string $ref,
        ?string $remote,
        ?FakeApi $api = new FakeApi(self::SPLITS),
    ): array {
        $application = new Application();
        $application->addCommand(new SplitCommand($repository->dir, $remote, $api));
        $tester = new CommandTester($application->find('split'));
        $code = $tester->execute(['ref' => $ref], ['decorated' => false]);

        return [$code, $tester->getDisplay()];
    }

    private static function repository(): Repository
    {
        $repository = new Repository();
        $repository->commit('Add a and b', ['packages/a/A.php' => 'a1', 'packages/b/B.php' => 'b1']);

        return $repository;
    }

    private static function remotes(string ...$packages): Remotes
    {
        $remotes = new Remotes();

        foreach ($packages as $package) {
            $remotes->git->run(['init', '--quiet', '--bare', $package . '.git']);
        }

        return $remotes;
    }
}
