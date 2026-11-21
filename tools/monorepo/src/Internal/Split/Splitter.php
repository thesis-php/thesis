<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Split;

use Thesis\Monorepo\Internal\Git\CatFile;
use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Internal\Monorepo;
use Thesis\Monorepo\Internal\MonorepoException;
use function Thesis\exceptionally;

/**
 * Splits the history of every packages/<name> of a commit in one pass: each commit of the monorepo that changes
 * what a package has in its split becomes a commit of the split with the same author, committer and message.
 *
 * @internal
 */
final class Splitter
{
    /**
     * @var array<non-empty-string, SplitCommit> id => commit of any package
     */
    private array $commits = [];

    /**
     * What a commit of the monorepo leaves to its children for each package: its split if it has the package,
     * otherwise the heads of its parents, so the history goes on across a removal of the package.
     * Dropped once its last child is split: only the frontier of the walk is kept in memory.
     *
     * @var array<string, array<string, list<SplitCommit>>> monorepo commit => package => split heads
     */
    private array $heads = [];

    /**
     * @var array<string, positive-int> monorepo commit => its children not split yet
     */
    private array $children = [];

    /**
     * @var array<string, true> monorepo commits split so far
     */
    private array $split = [];

    /**
     * @var array<string, non-empty-string> split commit of any package => the monorepo commit it was made from
     */
    private array $origins = [];

    /**
     * Most commits leave most packages as they are: the tree of a split is made once per change of the package.
     * The splitter keeps only the latest entry for each package, so memory use stays bounded as history grows.
     *
     * @var array<string, array{package: non-empty-string, split: non-empty-string}> package => its last trees
     */
    private array $trees = [];

    /**
     * @param non-empty-string $commit the id of a commit, resolved once by the caller: a ref may move meanwhile
     */
    public static function split(Git $git, string $commit): Splits
    {
        if (trim($git->run(['rev-parse', '--is-shallow-repository'])) === 'true') {
            throw new MonorepoException('The history is shallow: fetch it whole (fetch-depth: 0 in CI).');
        }

        if (trim($git->run(['rev-parse', '--show-object-format'])) !== 'sha1') {
            throw new MonorepoException('Only a repository of SHA-1 objects can be split.');
        }

        $splitter = new self(
            catFile: $git->catFile(),
            objects: trim($git->run(['rev-parse', '--path-format=absolute', '--git-path', 'objects'])),
        );
        $packages = array_keys($splitter->catFile->subtrees($commit . ':' . Monorepo::PACKAGES_DIR));

        if ($packages === []) {
            return new Splits(commits: [], origins: []);
        }

        // --topo-order lists every parent before its children, whatever the dates say.
        $history = explode("\n", trim($git->run(['rev-list', '--reverse', '--topo-order', '--parents', $commit])));

        foreach ($history as $line) {
            foreach (\array_slice(explode(' ', $line), 1) as $parent) {
                $splitter->children[$parent] = ($splitter->children[$parent] ?? 0) + 1;
            }
        }

        foreach ($history as $line) {
            $parents = explode(' ', $line);
            $child = array_shift($parents);

            if ($child === '') {
                throw new MonorepoException(\sprintf('git rev-list: no commit in the line "%s".', $line));
            }

            $splitter->splitCommit($child, $parents, $packages);
        }

        $commits = [];

        foreach ($packages as $package) {
            $heads = $splitter->heads[$commit][$package] ?? [];

            if (\count($heads) !== 1) {
                throw new MonorepoException(\sprintf('%s of %s has no single split commit.', $package, $commit));
            }

            $commits[$package] = $heads[0]->id;
        }

        return new Splits($commits, $splitter->origins);
    }

    private function __construct(
        private readonly CatFile $catFile,
        private readonly string $objects,
    ) {}

    /**
     * @param non-empty-string $commit
     * @param list<string> $parents
     * @param list<string> $packages
     */
    private function splitCommit(string $commit, array $parents, array $packages): void
    {
        foreach ($parents as $parent) {
            if (!isset($this->split[$parent])) {
                throw new MonorepoException(\sprintf('The parent %s of %s is not split yet.', $parent, $commit));
            }
        }

        $trees = $this->catFile->subtrees($commit . ':' . Monorepo::PACKAGES_DIR);
        $raw = null;

        foreach ($packages as $package) {
            $heads = $this->parentHeads($package, $parents);
            $tree = isset($trees[$package]) ? $this->splitTree($package, $trees[$package]) : null;
            $unchanged = \count($heads) === 1 && $heads[0]->tree === $tree;

            if ($tree !== null && !$unchanged) {
                $raw ??= $this->catFile->read($commit, type: 'commit')
                    ?? throw new MonorepoException(\sprintf('No commit %s.', $commit));
                $heads = [$this->write($tree, $heads, $raw)];
                $this->origins[$heads[0]->id] ??= $commit;
            }

            $this->heads[$commit][$package] = $heads;
        }

        $this->split[$commit] = true;

        foreach ($parents as $parent) {
            $left = ($this->children[$parent] ?? 1) - 1;

            if ($left > 0) {
                $this->children[$parent] = $left;
            } else {
                unset($this->heads[$parent], $this->children[$parent]);
            }
        }
    }

    /**
     * The entries of the package that its list names (Monorepo::SPLIT). A package without a list is split whole,
     * as every package was before the lists: the commits of that time keep their ids.
     *
     * @param non-empty-string $tree the tree of the package
     * @return non-empty-string the tree of its split
     */
    private function splitTree(string $package, string $tree): string
    {
        $last = $this->trees[$package] ?? null;

        if ($last !== null && $last['package'] === $tree) {
            return $last['split'];
        }

        $list = $this->catFile->read($tree . ':' . Monorepo::SPLIT, type: 'blob');
        $split = $tree;

        if ($list !== null) {
            // A line that names no entry keeps nothing: the list needs no syntax for its header.
            $listed = array_flip(explode("\n", $list));
            $entries = '';

            foreach ($this->catFile->entries($tree) as $entry) {
                if (isset($listed[$entry->name])) {
                    $entries .= $entry->bytes;
                }
            }

            $split = $this->store('tree', $entries);
        }

        $this->trees[$package] = ['package' => $tree, 'split' => $split];

        return $split;
    }

    /**
     * The splitter keeps unique parent heads and drops any head that is an ancestor of another head:
     * such a parent adds nothing to a merge, so the history of a package stays linear where only
     * another directory had a branch.
     *
     * @param list<string> $parents
     * @return list<SplitCommit>
     */
    private function parentHeads(string $package, array $parents): array
    {
        if (\count($parents) === 1) {
            return $this->heads[$parents[0]][$package] ?? [];
        }

        $heads = [];

        foreach ($parents as $parent) {
            foreach ($this->heads[$parent][$package] ?? [] as $head) {
                $heads[$head->id] ??= $head;
            }
        }

        $heads = array_values($heads);

        return array_values(array_filter(
            $heads,
            fn(SplitCommit $head) => array_all(
                $heads,
                fn(SplitCommit $other) => $other === $head || !$this->isAncestor($head, $other),
            ),
        ));
    }

    private function isAncestor(SplitCommit $ancestor, SplitCommit $commit): bool
    {
        $queue = [$commit];
        $seen = [];

        while (($next = array_pop($queue)) !== null) {
            foreach ($next->parents as $id) {
                if ($id === $ancestor->id) {
                    return true;
                }

                $parent = $this->commits[$id] ?? throw new MonorepoException(\sprintf('No split commit %s.', $id));

                // An ancestor has a lower generation than any of its descendants: none of this lower is one.
                if ($parent->generation > $ancestor->generation && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $queue[] = $parent;
                }
            }
        }

        return false;
    }

    /**
     * Copies the author, the committer, the encoding and the message of the commit. Every other header
     * is dropped: a signature (gpgsig, mergetag) signs the commit of the monorepo, not this one.
     *
     * @param non-empty-string $tree
     * @param list<SplitCommit> $parents
     */
    private function write(string $tree, array $parents, string $raw): SplitCommit
    {
        $end = strpos($raw, "\n\n");
        $headers = $end === false ? $raw : substr($raw, 0, $end);
        $message = $end === false ? '' : substr($raw, $end + 2);
        $body = 'tree ' . $tree . "\n";

        foreach ($parents as $parent) {
            $body .= 'parent ' . $parent->id . "\n";
        }

        foreach (explode("\n", $headers) as $header) {
            if (preg_match('/^(author|committer|encoding) /', $header) === 1) {
                $body .= $header . "\n";
            }
        }

        $body .= "\n" . $message;
        $id = $this->store('commit', $body);

        $this->commits[$id] ??= new SplitCommit(
            id: $id,
            tree: $tree,
            parents: array_map(static fn(SplitCommit $parent) => $parent->id, $parents),
            generation: 1 + max([0, ...array_map(static fn(SplitCommit $parent) => $parent->generation, $parents)]),
        );

        return $this->commits[$id];
    }

    /**
     * Writes a loose object the way git does, without a process per object. A file left by an earlier run
     * is kept only if it holds this very object: a broken one would be pushed as it is.
     *
     * @param 'commit'|'tree' $type
     * @return non-empty-string the id of the object
     */
    private function store(string $type, string $body): string
    {
        $object = $type . ' ' . \strlen($body) . "\0" . $body;
        $id = sha1($object);
        $dir = $this->objects . '/' . substr($id, 0, 2);
        $path = $dir . '/' . substr($id, 2);
        $existing = is_file($path) ? @file_get_contents($path) : false;

        if ($existing !== false && @gzuncompress($existing) === $object) {
            return $id;
        }

        $temporary = $dir . '/tmp_obj_' . bin2hex(random_bytes(8));

        try {
            exceptionally(static function () use ($dir, $temporary, $path, $object): void {
                if (!is_dir($dir)) {
                    mkdir($dir);
                }

                file_put_contents($temporary, gzcompress($object));
                chmod($temporary, 0o444);
                rename($temporary, $path);
            });
        } catch (\ErrorException $error) {
            @unlink($temporary);

            throw new MonorepoException(
                message: \sprintf('Cannot write the object %s: %s.', $id, $error->getMessage()),
                previous: $error,
            );
        }

        return $id;
    }
}
