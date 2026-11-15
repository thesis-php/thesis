<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Git;

use Thesis\Monorepo\Internal\MonorepoException;

/**
 * Reads objects through one `git cat-file --batch`: a process per object would cost more than the whole split.
 *
 * @internal
 */
final class CatFile
{
    private const int TREE_MODE = 0o40000;

    /**
     * @var resource
     */
    private $process;

    /**
     * @var resource
     */
    private $input;

    /**
     * @var resource
     */
    private $output;

    /**
     * @param array<string, string> $env
     */
    public function __construct(string $dir, array $env)
    {
        $process = proc_open(
            command: ['git', '-C', $dir, 'cat-file', '--batch'],
            descriptor_spec: [['pipe', 'r'], ['pipe', 'w'], STDERR],
            pipes: $pipes,
            env_vars: $env,
        );

        if ($process === false) {
            throw new MonorepoException('git cat-file: cannot start a process.');
        }

        $this->process = $process;
        $this->input = $pipes[0] ?? throw new MonorepoException('git cat-file: no input.');
        $this->output = $pipes[1] ?? throw new MonorepoException('git cat-file: no output.');
    }

    /**
     * @param non-empty-string $object an object name git understands without a newline, e.g. `<commit>:packages`
     * @param 'blob'|'commit'|'tag'|'tree' $type
     * @return ?string the content, null if there is no such object of this type
     */
    public function read(string $object, string $type): ?string
    {
        if (str_contains($object, "\n")) {
            throw new MonorepoException(\sprintf('git cat-file: %s is not an object name.', json_encode($object)));
        }

        fwrite($this->input, $object . "\n");
        fflush($this->input);
        $header = fgets($this->output);

        if ($header === false) {
            throw new MonorepoException(\sprintf('git cat-file: no answer for %s.', $object));
        }

        // "<id> <type> <size>" for an object, "<name> missing" or "<name> ambiguous" otherwise.
        if (preg_match('/^[0-9a-f]{40} ([a-z]+) (\d+)\n$/D', $header, $match) !== 1) {
            return null;
        }

        $size = (int) $match[2];
        $content = '';

        // A read of a pipe may return less than asked for.
        while (($missing = $size - \strlen($content)) > 0) {
            $chunk = fread($this->output, $missing);

            if ($chunk === false || $chunk === '') {
                throw new MonorepoException(\sprintf('git cat-file: truncated %s.', $object));
            }

            $content .= $chunk;
        }

        if (fgetc($this->output) !== "\n") {
            throw new MonorepoException(\sprintf('git cat-file: malformed answer for %s.', $object));
        }

        return $match[1] === $type ? $content : null;
    }

    /**
     * @param non-empty-string $object
     * @return array<string, non-empty-string> name => id of every subtree, empty if the object is not a tree
     */
    public function subtrees(string $object): array
    {
        $tree = $this->read($object, type: 'tree') ?? '';
        $subtrees = [];
        $offset = 0;

        // Every entry is "<octal mode> <name>\0<20 bytes of id>".
        while ($offset < \strlen($tree)) {
            $space = strpos($tree, ' ', $offset);
            $nul = $space === false ? false : strpos($tree, "\0", $space);

            if ($space === false || $nul === false || $nul + 21 > \strlen($tree)) {
                throw new MonorepoException(\sprintf('git cat-file: %s is a malformed tree.', $object));
            }

            if (\intval(substr($tree, $offset, $space - $offset), 8) === self::TREE_MODE) {
                $id = bin2hex(substr($tree, $nul + 1, 20));
                \assert($id !== '');
                $subtrees[substr($tree, $space + 1, $nul - $space - 1)] = $id;
            }

            $offset = $nul + 21;
        }

        return $subtrees;
    }

    public function __destruct()
    {
        fclose($this->input);
        fclose($this->output);
        proc_close($this->process);
    }
}
