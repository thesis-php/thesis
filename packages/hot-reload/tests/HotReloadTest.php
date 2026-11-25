<?php

declare(strict_types=1);

namespace Thesis\HotReload;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Io\Buffer;
use Thesis\Io\Stream;
use Thesis\Process;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\exceptionally;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class HotReloadTest
{
    public function restartsOnSighupAfterTheChildCrashes(): void
    {
        $script = <<<'PHP_WORKER'
            fwrite(STDOUT, "ready\n");
            exit(fgetc(STDIN) === '!' ? 42 : 0);
            PHP_WORKER;

        self::withSupervisor(
            arguments: ['--', PHP_BINARY, '-r', $script],
            test: static function (\Closure $readLine, Process $supervisor, Stream $stream) {
                Assert::same(timeout(sec(5), $readLine), 'ready');

                $stream->write('!');

                Assert::string(timeout(sec(5), $readLine))->matchesRegex('/^Process \d+ exited with code 42\.$/');
                Assert::null(timeoutOr(ms(150), $readLine));

                $supervisor->signal(SIGHUP);

                Assert::same(timeout(sec(5), $readLine), 'ready');
            },
        );
    }

    public function terminalInterruptLetsTheWorkerFinishCleanup(): void
    {
        $launcher = <<<'PHP_LAUNCHER'
            if (posix_setsid() < 0 || !fopen(posix_ttyname(STDIN), 'r+')) {
                exit(1);
            }
            pcntl_exec(PHP_BINARY, array_slice($argv, 1));
            PHP_LAUNCHER;
        $worker = <<<'PHP_WORKER'
            $terminal = fopen('/dev/tty', 'r+');
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function () use ($terminal) {
                pcntl_signal(SIGINT, SIG_DFL);
                fwrite($terminal, "stopping\n");
                if (trim(fgets($terminal)) === 'finish') {
                    fwrite($terminal, "finished\n");
                    exit(0);
                }
                exit(1);
            });
            fwrite($terminal, "ready\n");
            sleep(15);
            PHP_WORKER;

        $binary = \dirname(__DIR__) . '/bin/hot-reload';
        $process = proc_open(
            command: [PHP_BINARY, '-r', $launcher, '--', $binary, '--', PHP_BINARY, '-r', $worker],
            descriptor_spec: [0 => ['pty'], 1 => ['null'], 2 => ['null']],
            pipes: $pipes,
        );
        \assert(\is_resource($process) && isset($pipes[0]));
        $terminal = new Stream($pipes[0]);
        $buffer = new Buffer();
        $readLine = static function () use ($terminal, $buffer) {
            $buffer->fillUntil($terminal, "\n");

            return trim($buffer->readUntil("\n") ?? '');
        };

        try {
            group(static function () use ($terminal, $readLine, $process) {
                Assert::same(timeout(sec(5), $readLine), 'ready');
                $terminal->write("\x03");
                Assert::string(timeout(sec(5), $readLine))->contains('stopping');
                $terminal->write("finish\n");
                timeout(sec(5), static function () use ($readLine) {
                    while ($readLine() !== 'finished');
                });
                timeout(sec(5), static function () use ($process) {
                    while (proc_get_status($process)['running']) {
                        delay(ms(10));
                    }
                });
            });
        } finally {
            proc_terminate($process);
            $terminal->close();
            proc_close($process);
        }
    }

    public function keepsHandlingSignalsUntilTheWorkerExits(): void
    {
        $script = <<<'PHP_WORKER'
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () {
                echo "stopping\n";
                exit(fgetc(STDIN) === '!' ? 0 : 1);
            });
            echo "ready\n";
            sleep(15);
            PHP_WORKER;

        self::withSupervisor(
            arguments: ['--', PHP_BINARY, '-r', $script],
            test: static function (\Closure $readLine, Process $supervisor, Stream $stream) {
                Assert::same(timeout(sec(5), $readLine), 'ready');
                $supervisor->signal(SIGTERM);
                Assert::same(timeout(sec(5), $readLine), 'stopping');
                $supervisor->signal(SIGHUP);
                $supervisor->signal(SIGINT);
                $stream->write('!');
                Assert::string(timeout(sec(5), $readLine))->matchesRegex('/^Process \d+ exited with code 0\.$/');
                Assert::same(timeout(sec(5), $supervisor->wait(...))->code, 0);
            },
        );
    }

    public function reloadsAfterAnAtomicSaveWithTheSameMtimeAndRecoversAfterAParseError(): void
    {
        $directory = sys_get_temp_dir() . '/thesis-hot-reload-' . bin2hex(random_bytes(8));
        $watched = $directory . '/src';
        $file = $watched . '/response.php';
        // Keep the staged file outside the watched directory until the rename.
        $replacement = $directory . '/next.php';
        $stamp = time() - 10;
        $server = <<<'PHP_WORKER'
            $body = require $argv[1];
            $listener = stream_socket_server('tcp://127.0.0.1:0');
            echo 'HTTP ', stream_socket_get_name($listener, false), "\n";
            $socket = stream_socket_accept($listener, 10);
            while (($line = fgets($socket)) !== false && $line !== "\r\n") {}
            fwrite($socket, 'HTTP/1.1 200 OK' . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
            fclose($socket);
            fgetc(STDIN);
            PHP_WORKER;

        try {
            exceptionally(static function () use ($watched, $file, $stamp) {
                mkdir($watched, recursive: true);
                file_put_contents($file, "<?php return 'before';");
                touch($file, $stamp);
            });

            self::withSupervisor(
                arguments: [
                    '--watch', $watched, '--debounce=50ms', '--',
                    PHP_BINARY, '-d', 'display_errors=stderr', '-r', $server, '--', $file,
                ],
                test: static function (\Closure $readLine, Process $supervisor) use ($file, $replacement, $stamp) {
                    Assert::same(self::readResponse($readLine), 'before');

                    exceptionally(static function () use ($file, $replacement, $stamp) {
                        file_put_contents($replacement, "<?php return 'second';");
                        touch($replacement, $stamp);
                        rename($replacement, $file);
                    });
                    clearstatcache(true, $file);
                    Assert::same(filemtime($file), $stamp);
                    Assert::same(self::readResponse($readLine), 'second');

                    exceptionally(static fn() => file_put_contents($file, '<?php function {'));

                    $failure = timeout(sec(5), static function () use ($readLine) {
                        $output = '';
                        do {
                            $line = $readLine();
                            $output .= $line . "\n";
                        } while (!str_ends_with($line, 'exited with code 255.'));

                        return $output;
                    });
                    Assert::string($failure)->contains('Parse error');

                    exceptionally(static function () use ($file) {
                        file_put_contents($file, "<?php return 'intermediate';");
                        file_put_contents($file, "<?php return 'recovered';");
                    });

                    Assert::same(self::readResponse($readLine), 'recovered');
                    Assert::null(timeoutOr(ms(600), $readLine));

                    $supervisor->signal(SIGHUP);
                    Assert::same(self::readResponse($readLine), 'recovered');
                },
            );
        } finally {
            @unlink($replacement);
            @unlink($file);
            @rmdir($watched);
            @rmdir($directory);
        }
    }

    public function combinesSavesDuringShutdownIntoOneReplacement(): void
    {
        $file = exceptionally(static fn() => tempnam(sys_get_temp_dir(), 'thesis-hot-reload-'));
        $lockFile = $file . '.lock';
        $script = <<<'PHP_WORKER'
            $lock = fopen($argv[2], 'c');
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                echo "Started before the previous process exited\n";
                exit(1);
            }
            $file = $argv[1];
            $version = file_get_contents($file);
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use ($file, $version) {
                echo "stopping {$version}\n";
                if ($version === 'initial' && fgetc(STDIN) === '!') {
                    file_put_contents($file, 'latest');
                }
                exit(0);
            });
            echo "started {$version}\n";
            sleep(15);
            PHP_WORKER;

        try {
            exceptionally(static fn() => file_put_contents($file, 'initial'));

            self::withSupervisor(
                arguments: ['--watch', $file, '--', PHP_BINARY, '-r', $script, '--', $file, $lockFile],
                test: static function (\Closure $readLine, Process $supervisor, Stream $stream) use ($file) {
                    Assert::same(timeout(sec(5), $readLine), 'started initial');

                    exceptionally(static fn() => file_put_contents($file, 'first edit'));
                    Assert::same(timeout(sec(5), $readLine), 'stopping initial');

                    exceptionally(static fn() => file_put_contents($file, 'edit during shutdown'));
                    Assert::null(timeoutOr(ms(350), $readLine));

                    // The old worker makes the final save immediately before releasing the lock.
                    $stream->write('!');
                    $readWorkerLine = static function () use ($readLine) {
                        do {
                            $line = $readLine();
                        } while (preg_match('/^Process \d+ exited with code 0\.$/', $line) === 1);

                        return $line;
                    };
                    Assert::same(timeout(sec(5), $readWorkerLine), 'started latest');
                    Assert::null(timeoutOr(ms(600), $readWorkerLine));

                    exceptionally(static fn() => file_put_contents($file, 'edit after launch'));
                    Assert::same(timeout(sec(5), $readWorkerLine), 'stopping latest');
                    Assert::same(timeout(sec(5), $readWorkerLine), 'started edit after launch');
                },
            );
        } finally {
            @unlink($lockFile);
            @unlink($file);
        }
    }

    /**
     * @param \Closure(): string $readLine
     */
    private static function readResponse(\Closure $readLine): string
    {
        $address = timeout(sec(5), static function () use ($readLine) {
            do {
                $line = $readLine();
            } while (!str_starts_with($line, 'HTTP '));

            return substr($line, 5);
        });

        return exceptionally(static fn() => file_get_contents(
            "http://{$address}/",
            context: stream_context_create(['http' => ['timeout' => 2]]),
        ));
    }

    /**
     * @param non-empty-list<string> $arguments
     * @param callable(\Closure(): string, Process, Stream): void $test
     */
    private static function withSupervisor(array $arguments, callable $test): void
    {
        $pair = exceptionally(static fn() => stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0));
        \assert(isset($pair[0], $pair[1]));
        [$parent, $child] = $pair;
        $stream = new Stream($parent);
        $binary = \dirname(__DIR__) . '/bin/hot-reload';

        group(static function (Group $group) use ($binary, $arguments, $stream, $child, $test) {
            try {
                $supervisor = Process::start(
                    group: $group,
                    command: [PHP_BINARY, $binary, ...$arguments],
                    descriptors: [0 => $child, 1 => $child, 2 => $child],
                );
            } finally {
                fclose($child);
            }

            $buffer = new Buffer();
            $readLine = static function () use ($buffer, $stream) {
                $buffer->fillUntil($stream, "\n");

                $line = $buffer->readUntil("\n");
                \assert($line !== null);

                return $line;
            };

            try {
                Assert::same(timeout(sec(5), $readLine), "Supervisor {$supervisor->pid} is listening for SIGHUP.");
                $test($readLine, $supervisor, $stream);
                $supervisor->signal(SIGTERM);
                Assert::same(timeout(sec(5), $supervisor->wait(...))->code, 0);
            } finally {
                $stream->close();
            }
        });
    }
}
