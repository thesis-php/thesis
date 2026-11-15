<?php

declare(strict_types=1);

namespace Thesis\Io;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;
use Thesis\Async\Group;
use function Thesis\Async\group;
use function Thesis\Async\timeoutOr;
use function Thesis\Time\sec;

#[Test]
final class StdioTest
{
    public function childCopiesStdinToStdoutAndStderr(): void
    {
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = <<<PHP
            require '{$autoload}';
            \$in = Thesis\\Io\\stdin();
            try {
                while (true) {
                    \$bytes = \$in->read(65536);
                    Thesis\\Io\\stdout()->write(strtoupper(\$bytes));
                    Thesis\\Io\\stderr()->write((string) strlen(\$bytes) . "\\n");
                }
            } catch (Thesis\\Io\\UnexpectedEof) {
            }
            PHP;
        $process = proc_open(
            command: [PHP_BINARY, '-r', $code],
            descriptor_spec: [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            pipes: $pipes,
        );
        \assert(\is_resource($process) && isset($pipes[0], $pipes[1], $pipes[2]));
        $data = str_repeat('abcdefgh', 500_000);

        [$stdout, $stderr] = group(static function (Group $group) use ($pipes, $data) {
            $stdin = new Stream($pipes[0]);
            $group->spawn(static function () use ($stdin, $data) {
                $stdin->write($data);
                $stdin->close();
            });
            $stderr = $group->spawn(static fn() => readAll(new Stream($pipes[2])));

            return [readAll(new Stream($pipes[1])), $stderr->await()];
        });

        Assert::same(proc_close($process), 0);
        Assert::true($stdout === strtoupper($data));
        Assert::same(array_sum(array_map(intval(...), explode("\n", trim($stderr)))), \strlen($data));
    }

    /**
     * The next process on the same stdout, as the next command of a shell, finds it blocking.
     */
    #[DataSet(['Thesis\Io\stdout()->write("used\n");'])]
    #[DataSet(['Thesis\Io\stdout()->close();'])]
    public function stdoutIsBlockingAgainForTheNextProcess(string $use): void
    {
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $first = escapeshellarg("require '{$autoload}'; {$use}");
        $next = escapeshellarg('echo stream_get_meta_data(STDOUT)["blocked"] ? "blocking" : "non-blocking";');
        $process = proc_open(
            command: \sprintf('%1$s -r %2$s; %1$s -r %3$s', escapeshellarg(PHP_BINARY), $first, $next),
            descriptor_spec: [1 => ['pipe', 'w']],
            pipes: $pipes,
        );
        \assert(\is_resource($process) && isset($pipes[1]));

        $output = readAll(new Stream($pipes[1]));

        Assert::same(proc_close($process), 0);
        Assert::true(str_ends_with($output, 'blocking') && !str_ends_with($output, 'non-blocking'));
    }

    /**
     * A process that shares stdout while ours runs (a child that inherits it, a job of the same terminal)
     * finds it blocking: our stream is non-blocking only inside its own read() and write() calls.
     */
    public function stdoutIsBlockingForOthersWhileInUse(): void
    {
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = <<<PHP
            require '{$autoload}';
            Thesis\\Io\\stdout()->write("used\\n");
            \$child = proc_open(
                [PHP_BINARY, '-r', 'echo stream_get_meta_data(STDOUT)["blocked"] ? "blocking" : "non-blocking";'],
                [1 => STDOUT],
                \$pipes,
            );
            proc_close(\$child);
            PHP;
        $process = proc_open(
            command: [PHP_BINARY, '-r', $code],
            descriptor_spec: [1 => ['pipe', 'w']],
            pipes: $pipes,
        );
        \assert(\is_resource($process) && isset($pipes[1]));

        $output = readAll(new Stream($pipes[1]));

        Assert::same(proc_close($process), 0);
        Assert::same($output, "used\nblocking");
    }

    /**
     * If a write blocked the process, the deadline could not fire, and the child would hang on a full pipe.
     */
    public function writeToAFullStdoutDoesNotBlockTheEventLoop(): void
    {
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = <<<PHP
            require '{$autoload}';
            Thesis\\Async\\timeoutOr(
                Thesis\\Time\\ms(100),
                static fn() => Thesis\\Io\\stdout()->write(str_repeat('x', 10_000_000)),
            );
            fwrite(STDERR, 'alive');
            PHP;
        $process = proc_open(
            command: [PHP_BINARY, '-r', $code],
            descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
        );
        \assert(\is_resource($process) && isset($pipes[1], $pipes[2]));
        $stdout = new Stream($pipes[1]);

        $stderr = timeoutOr(sec(5), static fn() => readAll(new Stream($pipes[2])), default: 'hung');
        $stdout->close();

        proc_close($process);
        Assert::same($stderr, 'alive');
    }
}
