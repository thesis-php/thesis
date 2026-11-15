<?php

declare(strict_types=1);

namespace Thesis\Monorepo\Internal\Git;

use Thesis\Monorepo\Internal\MonorepoException;

/**
 * Runs git in one repository. The output of a command goes to a temporary file, not a pipe:
 * a full pipe of stderr would block a process that nobody reads yet.
 *
 * @internal
 */
final readonly class Git
{
    /**
     * The objects of the history are what they are: replace refs and grafts of a clone would change what
     * a command reads, and a prompt for a password would hang CI. A graft file that exists, even an empty one,
     * makes git print a warning to every command, so it is a path that cannot exist.
     */
    private const array ENV = [
        'GIT_NO_REPLACE_OBJECTS' => '1',
        'GIT_GRAFT_FILE' => '/dev/null/grafts',
        'GIT_TERMINAL_PROMPT' => '0',
    ];

    /**
     * @param array<string, string> $env added to the environment of every command
     */
    public function __construct(
        public string $dir,
        private array $env = [],
    ) {}

    /**
     * @param array<string, string> $env
     */
    public function with(array $env): self
    {
        return new self($this->dir, [...$this->env, ...$env]);
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args, string $input = ''): string
    {
        $result = self::wait($this->start($args, $input));

        if ($result->code !== 0) {
            $error = trim($result->stderr);

            throw new MonorepoException(\sprintf(
                'git %s: %s',
                implode(' ', $args),
                $error === '' ? 'exit code ' . $result->code : $error,
            ));
        }

        return $result->stdout;
    }

    /**
     * @param list<string> $args
     */
    public function succeeds(array $args): bool
    {
        return self::wait($this->start($args))->code === 0;
    }

    /**
     * Runs the commands in parallel and waits for all of them.
     *
     * @template K of array-key
     * @param array<K, list<string>> $commands
     * @return array<K, Result>
     */
    public function runAll(array $commands): array
    {
        return array_map(self::wait(...), array_map($this->start(...), $commands));
    }

    public function catFile(): CatFile
    {
        return new CatFile($this->dir, $this->environment());
    }

    /**
     * The environment of the caller without its GIT_* variables: a hook or a parent git sets GIT_DIR,
     * GIT_INDEX_FILE and the like, which would point a command at another repository.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $inherited = array_filter(
            getenv(),
            static fn(string $name) => !str_starts_with($name, 'GIT_'),
            ARRAY_FILTER_USE_KEY,
        );

        return [...$inherited, ...self::ENV, ...$this->env];
    }

    /**
     * @param list<string> $args
     * @return array{resource, resource, resource} the process, its stdout and its stderr
     */
    private function start(array $args, string $input = ''): array
    {
        $stdout = self::temporaryFile();
        $stderr = self::temporaryFile();
        $process = proc_open(
            command: ['git', '-C', $this->dir, ...$args],
            descriptor_spec: [['pipe', 'r'], $stdout, $stderr],
            pipes: $pipes,
            env_vars: $this->environment(),
        );
        $stdin = $pipes[0] ?? null;

        if ($process === false || $stdin === null) {
            throw new MonorepoException('git: cannot start a process.');
        }

        fwrite($stdin, $input);
        fclose($stdin);

        return [$process, $stdout, $stderr];
    }

    /**
     * @param array{resource, resource, resource} $running
     */
    private static function wait(array $running): Result
    {
        [$process, $stdout, $stderr] = $running;
        $code = proc_close($process);

        return new Result(
            code: $code,
            stdout: self::contents($stdout),
            stderr: self::contents($stderr),
        );
    }

    /**
     * @return resource
     */
    private static function temporaryFile()
    {
        $file = tmpfile();

        if ($file === false) {
            throw new MonorepoException('git: cannot create a temporary file.');
        }

        return $file;
    }

    /**
     * @param resource $file
     */
    private static function contents($file): string
    {
        rewind($file);
        $contents = stream_get_contents($file);
        fclose($file);

        return $contents === false ? '' : $contents;
    }
}
