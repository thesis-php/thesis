<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Split;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Monorepo\Internal\MonorepoException;
use Thesis\Monorepo\Internal\Split\Splitter;

#[Test]
final readonly class SplitterTest
{
    public static function copiesOnlyCommitsThatChangeThePackage(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', ['packages/a/A.php' => 'a1', 'packages/b/B.php' => 'b1']);
        $repository->commit('Change b', ['packages/b/B.php' => 'b2']);
        $repository->commit('Change the root', ['README.md' => 'readme']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);

        $commits = self::split($repository);

        Assert::same(array_keys($commits), ['a', 'b']);
        Assert::same($repository->log($commits['a'] ?? ''), ['Change a', 'Add a and b']);
        Assert::same($repository->log($commits['b'] ?? ''), ['Change b', 'Add a and b']);
        Assert::same($repository->id(($commits['a'] ?? '') . '^{tree}'), $repository->id('main:packages/a'));
        Assert::same(
            actual: $repository->git->run(['log', '-1', '--format=%an %ae %aI|%cn %ce %cI|%B', $commits['a'] ?? '']),
            expected: $repository->git->run(['log', '-1', '--format=%an %ae %aI|%cn %ce %cI|%B', 'main']),
        );
        $repository->git->run(['fsck', '--strict', '--no-dangling']);
    }

    /**
     * The ids are the published history: the pushes are never forced, so a split that gave other ids
     * for the same commits could not be pushed. This history is the one splitsh-lite gave the same ids for.
     */
    public static function givesTheSameIdsForLinearHistory(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);

        Assert::same(self::split($repository), ['a' => '9040bdab2fb8dc6aaf3702caa271f5318e28440a']);
        Assert::same(self::split($repository), ['a' => '9040bdab2fb8dc6aaf3702caa271f5318e28440a']);
    }

    /**
     * Pins every rule at once: copied and dropped headers, kept and dropped merges, the order of parents,
     * a package removed and added again.
     */
    public static function givesTheSameIdsForEveryKindOfCommit(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', ['packages/a/A.php' => 'a1', 'packages/b/B.php' => 'b1']);
        $repository->git->run(['switch', '--quiet', '--create', 'feature']);
        $repository->commit('Change a and b on feature', ['packages/a/F.php' => 'f', 'packages/b/B.php' => 'b2']);
        $repository->git->run(['switch', '--quiet', 'main']);
        $repository->commit('Change a on main', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['merge', '--quiet', '--no-ff', '--message', 'Merge feature', 'feature']);
        $repository->commit('Remove a', ['packages/a/A.php' => null, 'packages/a/F.php' => null]);
        $repository->commit('Add a again', ['packages/a/A.php' => 'a3']);
        $repository->commit('Signed', ['packages/a/A.php' => 'a4']);
        $repository->commitObject(self::signedCommit(
            tree: $repository->id('HEAD^{tree}'),
            parent: $repository->id('HEAD^'),
            message: 'Signed, without a newline at the end',
        ));
        $repository->commit('Change b', ['packages/b/B.php' => 'b3']);

        $commits = self::split($repository);

        Assert::same($repository->log($commits['a'] ?? ''), [
            'Signed, without a newline at the end',
            'Add a again',
            'Merge feature +',
            'Change a on main',
            'Change a and b on feature',
            'Add a and b',
        ]);
        Assert::same($repository->log($commits['b'] ?? ''), ['Change b', 'Change a and b on feature', 'Add a and b']);
        Assert::same($commits, [
            'a' => '39aa94fa50fada94b83f611b43b320d8d658bdc5',
            'b' => '4281008826746deced0196308c220e1c15f434c9',
        ]);
        $repository->git->run(['fsck', '--strict', '--no-dangling']);
    }

    public static function continuesHistoryOfPackageAddedAgain(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1', 'packages/b/B.php' => 'b1']);
        $repository->commit('Remove a', ['packages/a/A.php' => null]);
        $repository->commit('Change b', ['packages/b/B.php' => 'b2']);
        $repository->commit('Add a again', ['packages/a/A.php' => 'a2']);

        Assert::same($repository->log(self::split($repository)['a'] ?? ''), ['Add a again', 'Add a']);
    }

    public static function keepsMergeOfBranchThatChangesThePackage(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1']);
        $repository->git->run(['switch', '--quiet', '--create', 'feature']);
        $repository->commit('Change a on feature', ['packages/a/Feature.php' => 'feature']);
        $repository->git->run(['switch', '--quiet', 'main']);
        $repository->commit('Change a on main', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['merge', '--quiet', '--no-ff', '--message', 'Merge feature', 'feature']);

        $split = self::split($repository)['a'] ?? '';

        Assert::same($repository->log($split), ['Merge feature +', 'Change a on main', 'Change a on feature', 'Add a']);
        Assert::same(
            actual: explode(' ', trim($repository->git->run(['log', '-1', '--format=%P', $split]))),
            expected: [self::split($repository, 'main^1')['a'] ?? '', self::split($repository, 'main^2')['a'] ?? ''],
        );
    }

    public static function dropsMergeOfBranchThatLeavesThePackageAsIs(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', ['packages/a/A.php' => 'a1', 'packages/b/B.php' => 'b1']);
        $repository->git->run(['switch', '--quiet', '--create', 'feature']);
        $repository->commit('Change b on feature', ['packages/b/B.php' => 'b2']);
        $repository->git->run(['switch', '--quiet', 'main']);
        $repository->commit('Change a on main', ['packages/a/A.php' => 'a2']);
        $repository->git->run(['merge', '--quiet', '--no-ff', '--message', 'Merge feature', 'feature']);

        $commits = self::split($repository);

        Assert::same($repository->log($commits['a'] ?? ''), ['Change a on main', 'Add a and b']);
        Assert::same($repository->log($commits['b'] ?? ''), ['Change b on feature', 'Add a and b']);
    }

    public static function dropsParentThatIsADistantAncestorOfAnother(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', ['packages/a/A.php' => 'a1', 'packages/b/B.php' => 'b1']);
        $repository->git->run(['switch', '--quiet', '--create', 'feature']);
        $repository->commit('Change b on feature', ['packages/b/B.php' => 'b2']);
        $repository->git->run(['switch', '--quiet', 'main']);
        $repository->commit('Change a on main', ['packages/a/A.php' => 'a2']);
        $repository->commit('Change a on main again', ['packages/a/A.php' => 'a3']);
        $repository->git->run(['merge', '--quiet', '--no-ff', '--message', 'Merge feature', 'feature']);

        Assert::same($repository->log(self::split($repository)['a'] ?? ''), [
            'Change a on main again',
            'Change a on main',
            'Add a and b',
        ]);
    }

    public static function doesNotRepeatParentThatBothSidesOfMergeShare(): void
    {
        $repository = new Repository();
        $repository->commit('Add a and b', ['packages/a/A.php' => 'a1', 'packages/b/B.php' => 'b1']);
        $repository->git->run(['switch', '--quiet', '--create', 'feature']);
        $repository->commit('Change b on feature', ['packages/b/Feature.php' => 'feature']);
        $repository->git->run(['switch', '--quiet', 'main']);
        $repository->commit('Change b on main', ['packages/b/B.php' => 'b2']);
        $repository->git->run(['merge', '--quiet', '--no-ff', '--message', 'Merge feature', 'feature']);

        Assert::same($repository->log(self::split($repository)['a'] ?? ''), ['Add a and b']);
    }

    /**
     * `git rev-list` without `--topo-order` would list the feature before its parent, which is younger.
     */
    public static function followsParentsNotDates(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1'], date: '2026-01-03T00:00:00Z');
        $repository->git->run(['switch', '--quiet', '--create', 'feature']);
        $repository->commit('Change a on feature', ['packages/a/F.php' => 'f'], date: '2026-01-01T00:00:00Z');
        $repository->git->run(['switch', '--quiet', 'main']);
        $repository->commit('Change a on main', ['packages/a/A.php' => 'a2'], date: '2026-01-02T00:00:00Z');
        $repository->git
            ->with(['GIT_COMMITTER_DATE' => '2026-01-04T00:00:00Z'])
            ->run(['merge', '--quiet', '--no-ff', '--message', 'Merge feature', 'feature'])
        ;

        $split = self::split($repository)['a'] ?? '';

        Assert::same($repository->git->run(['rev-list', '--count', $split]), "4\n");
        Assert::same($repository->git->run(['rev-list', '--count', '--max-parents=0', $split]), "1\n");
    }

    /**
     * The objects of history are what they are: grafts and replace refs of a clone must not change the split.
     */
    public static function ignoresReplacedObjects(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1']);
        $repository->commit('Change a', ['packages/a/A.php' => 'a2']);
        $before = self::split($repository);
        $repository->git->run(['replace', '--graft', 'HEAD']);
        file_put_contents($repository->dir . '/.git/info/grafts', $repository->head() . "\n");

        Assert::same(self::split($repository), $before);
    }

    public static function splitsOnlyDirectoriesOfPackages(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1', 'packages/README.md' => 'readme']);
        symlink('a', $repository->dir . '/packages/link');
        $repository->commit('Add a link');

        Assert::same(array_keys(self::split($repository)), ['a']);
    }

    /**
     * History cannot be changed: a commit where packages was not a directory must not break any split after it.
     */
    public static function skipsCommitWherePackagesIsNotDirectory(): void
    {
        $repository = new Repository();
        $repository->commit('Add packages as a file', ['packages' => 'file']);
        $repository->commit('Remove the file', ['packages' => null]);
        symlink('elsewhere', $repository->dir . '/packages');
        $repository->commit('Add packages as a link');
        unlink($repository->dir . '/packages');
        $repository->commit('Add a', ['packages/a/A.php' => 'a1']);

        Assert::same($repository->log(self::split($repository)['a'] ?? ''), ['Add a']);
    }

    /**
     * Only the frontier of the walk is kept: the heads of every package for every commit used to grow with
     * the packages times the commits and exhausted the memory of a long history.
     */
    public static function keepsMemoryOfHeadsFlatAsHistoryGrows(): void
    {
        $repository = new Repository();
        $repository->git->run(['fast-import', '--quiet'], self::history(commits: 3000, packages: 20));
        $commit = $repository->id('main');
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $split = Splitter::split($repository->git, $commit);

        Assert::int(memory_get_peak_usage() - $before)->lessThan(4 * 1024 * 1024);
        Assert::same(\count($split), 20);
        Assert::same($repository->git->run(['rev-list', '--count', $split['p0'] ?? '']), "150\n");
    }

    /**
     * The headers git writes after the message of a merge or a signed commit sign the monorepo commit,
     * an unknown header means nothing to the split.
     */
    public static function copiesAuthorCommitterEncodingAndMessageOnly(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1']);
        $repository->commit('Signed', ['packages/a/A.php' => 'a2']);
        $repository->commitObject(self::signedCommit(
            tree: $repository->id('HEAD^{tree}'),
            parent: $repository->id('HEAD^'),
            message: "Signed\n\nwith a body",
        ));

        $split = self::split($repository)['a'] ?? '';

        Assert::same($repository->git->run(['cat-file', 'commit', $split]), implode("\n", [
            'tree ' . $repository->id('main:packages/a'),
            'parent ' . $repository->id($split . '^'),
            'author Author <author@example.com> 1767225600 +0000',
            'committer Committer <committer@example.com> 1767225600 +0000',
            'encoding ISO-8859-1',
            '',
            "Signed\n\nwith a body",
        ]));
    }

    /**
     * A run that was killed while writing leaves a broken object, the next one must not push it as it is.
     */
    public static function rewritesBrokenObjectOfEarlierRun(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a1']);
        $split = self::split($repository)['a'] ?? '';
        $object = $repository->dir . '/.git/objects/' . substr($split, 0, 2) . '/' . substr($split, 2);
        unlink($object);
        file_put_contents($object, '');

        Assert::same(self::split($repository), ['a' => $split]);
        Assert::same($repository->git->run(['cat-file', '-t', $split]), "commit\n");
        $repository->git->run(['fsck', '--strict', '--no-dangling']);
    }

    public static function failsOnShallowHistory(): void
    {
        $origin = new Repository();
        $origin->commit('Add a', ['packages/a/A.php' => 'a1']);
        $origin->commit('Change a', ['packages/a/A.php' => 'a2']);
        $clone = new Repository();
        $clone->git->run(['fetch', '--quiet', '--depth=1', 'file://' . $origin->dir, 'main:shallow']);

        Expect::exception(MonorepoException::class)->withMessage(
            'The history is shallow: fetch it whole (fetch-depth: 0 in CI).',
        );

        self::split($clone, 'shallow');
    }

    public static function failsOnRepositoryOfSha256(): void
    {
        $repository = new Repository(objectFormat: 'sha256');
        $repository->commit('Add a', ['packages/a/A.php' => 'a1']);

        Expect::exception(MonorepoException::class)->withMessage('Only a repository of SHA-1 objects can be split.');

        self::split($repository);
    }

    /**
     * @return array<string, non-empty-string>
     */
    private static function split(Repository $repository, string $revision = 'HEAD'): array
    {
        return Splitter::split($repository->git, $repository->id($revision . '^{commit}'));
    }

    /**
     * A linear history where every commit changes the next package, as a stream of `git fast-import`.
     */
    private static function history(int $commits, int $packages): string
    {
        $stream = '';

        for ($i = 1; $i <= $commits; ++$i) {
            $content = (string) $i;
            $stream .= "commit refs/heads/main\n"
                . "mark :{$i}\n"
                . "committer Committer <committer@example.com> 1767225600 +0000\n"
                . "data 7\nChange\n"
                . ($i === 1 ? '' : 'from :' . ($i - 1) . "\n")
                . 'M 100644 inline packages/p' . ($i % $packages) . "/P.php\n"
                . 'data ' . \strlen($content) . "\n{$content}\n";
        }

        return $stream;
    }

    /**
     * A signature line that is blank is a single space: an empty line would end the headers.
     */
    private static function signedCommit(string $tree, string $parent, string $message): string
    {
        return implode("\n", [
            "tree {$tree}",
            "parent {$parent}",
            'author Author <author@example.com> 1767225600 +0000',
            'committer Committer <committer@example.com> 1767225600 +0000',
            'encoding ISO-8859-1',
            'x-unknown header',
            'mergetag object ' . $parent,
            ' type commit',
            ' tag v1',
            ' tagger Tagger <tagger@example.com> 1767225600 +0000',
            ' ',
            ' author Not an author <not@example.com> 1767225600 +0000',
            'gpgsig -----BEGIN PGP SIGNATURE-----',
            ' ',
            ' signature',
            ' -----END PGP SIGNATURE-----',
            '',
            $message,
        ]);
    }
}
