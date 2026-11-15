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

    public function writeCancelledAfterAPartLeavesStdoutUsable(): void
    {
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = <<<PHP
            require '{$autoload}';
            Thesis\\Async\\timeoutOr(
                Thesis\\Time\\ms(100),
                static fn() => Thesis\\Io\\stdout()->write(str_repeat('x', 10_000_000)),
            );
            fwrite(STDERR, "cancelled\\n");
            Thesis\\Io\\stdout()->write('after');
            PHP;
        $process = proc_open(
            command: [PHP_BINARY, '-r', $code],
            descriptor_spec: [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
        );
        \assert(\is_resource($process) && isset($pipes[1], $pipes[2]));
        $stderr = new Buffer();

        // the pipe of stdout stays full until the write has been cancelled
        $stderr->fillUntil(new Stream($pipes[2]), "\n");
        $stdout = readAll(new Stream($pipes[1]));

        Assert::same(proc_close($process), 0);
        Assert::same($stderr->readUntil("\n"), 'cancelled');
        Assert::true(str_ends_with($stdout, 'xafter'));
    }

    public function stdinIsBlockingForOthersWhileAReadWaitsAndAfterItIsCancelled(): void
    {
        $code = <<<'PHP_WRAP'
            $check = static function () {
                $child = proc_open(
                    [PHP_BINARY, '-r', 'fwrite(STDERR, stream_get_meta_data(STDIN)["blocked"] ? "blocking\n" : "non-blocking\n");'],
                    [0 => STDIN],
                    $pipes,
                );
                proc_close($child);
            };
            Thesis\Async\group(static function (Thesis\Async\Group $group) use ($check) {
                $read = $group->spawn(static fn() => Thesis\Io\stdin()->read(10));
                Thesis\Async\delay(Thesis\Time\ms(50));
                $check();
                $read->cancel();
            });
            $check();
            PHP_WRAP;

        $stderr = self::runChild($code, [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], output: 2);

        Assert::same($stderr, "blocking\nblocking\n");
    }

    public function stdoutIsBlockingForOthersWhileAWriteWaitsForRoom(): void
    {
        $code = <<<'PHP_WRAP'
            Thesis\Async\group(static function (Thesis\Async\Group $group) {
                $write = $group->spawn(static fn() => Thesis\Io\stdout()->write(str_repeat('x', 10_000_000)));
                Thesis\Async\delay(Thesis\Time\ms(50));
                $child = proc_open(
                    [PHP_BINARY, '-r', 'fwrite(STDERR, stream_get_meta_data(STDOUT)["blocked"] ? "blocking" : "non-blocking");'],
                    [1 => STDOUT, 2 => STDERR],
                    $pipes,
                );
                proc_close($child);
                $write->cancel();
            });
            PHP_WRAP;

        $stderr = self::runChild($code, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], output: 2);

        Assert::same($stderr, 'blocking');
    }

    /**
     * Runs $code in a child on the driver of the tests and returns what it writes to the pipe $output.
     * The other pipes are neither written nor read, so a read of the child waits and a write fills its pipe.
     *
     * @param array<int, list<string>> $descriptors
     */
    private static function runChild(string $code, array $descriptors, int $output): string
    {
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $process = proc_open(
            command: [PHP_BINARY, '-r', "require '{$autoload}'; {$code}"],
            descriptor_spec: $descriptors,
            pipes: $pipes,
        );
        \assert(\is_resource($process) && isset($pipes[$output]));

        $bytes = timeoutOr(sec(5), static fn() => readAll(new Stream($pipes[$output])), default: 'hung');

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($process);

        return $bytes;
    }
}
