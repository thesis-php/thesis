<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Tests\Split;

use Thesis\Monorepo\Internal\Git\Git;
use Thesis\Monorepo\Tests\Fixture;

/**
 * A git repository in a temporary directory. The identity and the dates are fixed, so the commits
 * and their splits have the same ids on every run.
 */
final readonly class Repository
{
    public const string DATE = '2026-01-01T00:00:00Z';

    public string $dir;

    public Git $git;

    /**
     * @param ?self $cloneOf a repository to clone with only the objects its refs reach, as CI has them:
     *     without the split commits that the source wrote
     */
    public function __construct(string $objectFormat = 'sha1', ?self $cloneOf = null)
    {
        $this->dir = sys_get_temp_dir() . '/split-' . bin2hex(random_bytes(8));
        mkdir($this->dir);
        $this->git = new Git($this->dir, env: [
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'Author',
            'GIT_AUTHOR_EMAIL' => 'author@example.com',
            'GIT_AUTHOR_DATE' => self::DATE,
            'GIT_COMMITTER_NAME' => 'Committer',
            'GIT_COMMITTER_EMAIL' => 'committer@example.com',
            'GIT_COMMITTER_DATE' => self::DATE,
        ]);
        if ($cloneOf === null) {
            $this->git->run(['init', '--quiet', '--initial-branch=main', '--object-format=' . $objectFormat]);
        } else {
            $this->git->run(['clone', '--quiet', '--no-local', 'file://' . $cloneOf->dir, '.']);
        }
    }

    /**
     * @param array<string, ?string> $files path => content, null removes the file
     * @return non-empty-string the commit
     */
    public function commit(string $message, array $files = [], string $date = self::DATE): string
    {
        foreach ($files as $path => $content) {
            $file = $this->dir . '/' . $path;

            if ($content === null) {
                unlink($file);

                continue;
            }

            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), recursive: true);
            }

            file_put_contents($file, $content);
        }

        $this->git->run(['add', '--all']);
        $this->git
            ->with(['GIT_AUTHOR_DATE' => $date, 'GIT_COMMITTER_DATE' => $date])
            ->run(['commit', '--quiet', '--allow-empty', '--message', $message])
        ;

        return $this->head();
    }

    /**
     * Writes a commit object as it is, e.g. with headers that `git commit` never writes.
     *
     * @return non-empty-string the commit, now the head of main
     */
    public function commitObject(string $raw): string
    {
        $commit = trim($this->git->run(['hash-object', '-t', 'commit', '-w', '--literally', '--stdin'], $raw));
        $this->git->run(['update-ref', 'refs/heads/main', $commit]);
        \assert($commit !== '');

        return $commit;
    }

    /**
     * @return non-empty-string
     */
    public function head(): string
    {
        return $this->id('HEAD');
    }

    /**
     * @return non-empty-string
     */
    public function id(string $revision): string
    {
        $id = trim($this->git->run(['rev-parse', '--verify', $revision]));
        \assert($id !== '');

        return $id;
    }

    /**
     * @return list<string> the subjects of the history of the commit, the newest first, merges marked with `+`
     */
    public function log(string $commit): array
    {
        $log = [];

        foreach (explode("\n", rtrim($this->git->run(['log', '--format=%P%x09%s', $commit]), "\n")) as $line) {
            $parents = (string) strstr($line, "\t", before_needle: true);
            $log[] = substr($line, \strlen($parents) + 1) . (str_contains($parents, ' ') ? ' +' : '');
        }

        return $log;
    }

    public function __destruct()
    {
        Fixture::remove($this->dir);
    }
}
