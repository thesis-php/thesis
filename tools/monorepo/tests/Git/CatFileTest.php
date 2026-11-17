<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Git;

use Testo\Assert;
use Testo\Test;
use Thesis\Monorepo\Tests\Split\Repository;

#[Test]
final readonly class CatFileTest
{
    public static function readsSubtreesOnly(): void
    {
        $repository = new Repository();
        $repository->commit('Add packages', [
            'packages/a/A.php' => 'a',
            'packages/with space/B.php' => 'b',
            'packages/README.md' => 'readme',
        ]);

        Assert::same($repository->git->catFile()->subtrees('HEAD:packages'), [
            'a' => $repository->id('HEAD:packages/a'),
            'with space' => $repository->id('HEAD:packages/with space'),
        ]);
    }

    public static function readsNoSubtreesOfObjectThatIsNotTree(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a']);
        $catFile = $repository->git->catFile();

        Assert::same($catFile->subtrees('HEAD:packages/a/A.php'), []);
        Assert::same($catFile->subtrees('HEAD'), []);
        Assert::same($catFile->subtrees('HEAD:missing'), []);
        Assert::same($catFile->subtrees('HEAD:packages'), ['a' => $repository->id('HEAD:packages/a')]);
    }

    /**
     * Old tools wrote the mode of a tree as 040000, git still reads such a tree as a directory.
     */
    public static function readsSubtreeWithZeroPaddedMode(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['packages/a/A.php' => 'a']);
        $subtree = $repository->id('HEAD:packages/a');
        $tree = trim($repository->git->run(
            ['hash-object', '-t', 'tree', '-w', '--literally', '--stdin'],
            "040000 a\0" . hex2bin($subtree),
        ));
        \assert($tree !== '');

        Assert::same($repository->git->catFile()->subtrees($tree), ['a' => $subtree]);
    }

    public static function readsEntriesOfTreeAsTheyAreWritten(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['a/src/A.php' => 'a', 'a/with space.md' => 'readme']);
        $src = $repository->id('HEAD:a/src');
        $readme = $repository->id('HEAD:a/with space.md');

        $entries = $repository->git->catFile()->entries('HEAD:a');

        Assert::same(
            actual: array_map(get_object_vars(...), $entries),
            expected: [
                ['name' => 'src', 'id' => $src, 'isTree' => true, 'bytes' => "40000 src\0" . hex2bin($src)],
                [
                    'name' => 'with space.md',
                    'id' => $readme,
                    'isTree' => false,
                    'bytes' => "100644 with space.md\0" . hex2bin($readme),
                ],
            ],
        );
    }

    public static function readsNoEntriesOfObjectThatIsNotTree(): void
    {
        $repository = new Repository();
        $repository->commit('Add a', ['a/A.php' => 'a']);
        $catFile = $repository->git->catFile();

        Assert::same($catFile->entries('HEAD:a/A.php'), []);
        Assert::same($catFile->entries('HEAD:missing'), []);
    }

    public static function readsObjectsOfAnySize(): void
    {
        $repository = new Repository();
        $content = str_repeat("line\n", 200_000);
        $repository->commit('Add a big file', ['packages/a/A.php' => $content, 'packages/a/Empty.php' => '']);
        $catFile = $repository->git->catFile();

        Assert::same($catFile->read('HEAD:packages/a/A.php', type: 'blob'), $content);
        Assert::same($catFile->read('HEAD:packages/a/Empty.php', type: 'blob'), '');
        Assert::same($catFile->read('HEAD:packages/a/A.php', type: 'tree'), null);
    }
}
