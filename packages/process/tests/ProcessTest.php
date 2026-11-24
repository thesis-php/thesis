<?php

declare(strict_types=1);

namespace Thesis\Process;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\TaskCancelled;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\Resumed;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Dispatcher;
use Thesis\Io\Stream;
use function Thesis\Async\checkpoint;
use function Thesis\Async\group;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\observe;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class ProcessTest
{
    public function passesASocketToTheWorkerAlongsideItsArgumentsDirectoryAndEnvironment(): void
    {
        $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        \assert(\is_array($pair) && isset($pair[0], $pair[1]));
        $ipc = new Stream($pair[0]);
        $child = $pair[1];
        $script = <<<'PHP_WORKER'
            $ipc = fopen('php://fd/3', 'r+');
            if (fgets($ipc) !== "ping\n") {
                exit(1);
            }
            fwrite($ipc, "!");
            exit(getcwd() === $argv[1] && getenv('WORKER_LABEL') === $argv[2] && getenv('EMPTY') === '' ? 0 : 2);
            PHP_WORKER;

        group(static function (Group $group) use ($ipc, $child, $script) {
            $worker = Process::start(
                group: $group,
                command: [PHP_BINARY, '-r', $script, '--', __DIR__, 'a b;$HOME'],
                descriptors: [3 => $child],
                cwd: __DIR__,
                env: ['WORKER_LABEL' => 'a b;$HOME', 'EMPTY' => ''],
            );
            fclose($child);
            $ipc->write("ping\n");

            Assert::same($ipc->read(1), '!');
            Assert::same($worker->wait()->code, 0);
        });
    }

    public function inheritsStandardStreamsByDefault(): void
    {
        $expected = [];
        foreach ([STDIN, STDOUT, STDERR] as $stream) {
            $stat = fstat($stream);
            \assert(\is_array($stat));
            $expected[] = [$stat['dev'], $stat['ino']];
        }
        $script = <<<'PHP_WORKER'
            $streams = [];
            foreach ([STDIN, STDOUT, STDERR] as $stream) {
                $stat = fstat($stream);
                $streams[] = [$stat['dev'], $stat['ino']];
            }
            exit(json_encode($streams) === $argv[1] ? 0 : 1);
            PHP_WORKER;

        group(static function (Group $group) use ($script, $expected) {
            $command = [PHP_BINARY, '-r', $script, '--', json_encode($expected, JSON_THROW_ON_ERROR)];
            Assert::same(Process::start($group, $command)->wait()->code, 0);
        });
    }

    public function aWorkerSeesIpcEofWhileItsSiblingIsStillRunning(): void
    {
        $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        \assert(\is_array($pair) && isset($pair[0], $pair[1]));
        [$parent, $child] = $pair;
        $script = <<<'PHP_WORKER'
            $ipc = fopen('php://fd/8', 'r+');
            exit(stream_get_contents($ipc) === 'request' ? 0 : 1);
            PHP_WORKER;

        group(static function (Group $group) use ($parent, $child, $script) {
            $worker = Process::start($group, [PHP_BINARY, '-r', $script], [8 => $child]);
            fclose($child);
            $sibling = Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']);
            $ipc = new Stream($parent);
            $ipc->write('request');
            $ipc->close();

            Assert::same(timeoutOr(sec(2), $worker->wait(...))?->code, 0);
            $sibling->signal(SIGTERM);
            Assert::same($sibling->wait()->signal, SIGTERM);
        });
    }

    public function mapsSparseDescriptorNumbersToTheRequestedStreams(): void
    {
        $files = [];
        foreach ([8, 12, 13] as $fd) {
            $file = tmpfile();
            \assert(\is_resource($file));
            fwrite($file, (string) $fd);
            rewind($file);
            $files[$fd] = $file;
        }
        $script = <<<'PHP_WORKER'
            foreach ([8, 12, 13] as $fd) {
                if (file_get_contents("php://fd/{$fd}") !== (string) $fd) {
                    exit(1);
                }
            }
            PHP_WORKER;

        group(static function (Group $group) use ($script, $files) {
            Assert::same(Process::start($group, [PHP_BINARY, '-r', $script], $files)->wait()->code, 0);
        });
    }

    public function collectsTheExitStatusOfEveryWorker(): void
    {
        group(static function (Group $group) {
            $workers = [];
            $gates = [];
            for ($i = 0; $i < 12; ++$i) {
                $pair = stream_socket_pair(
                    domain: STREAM_PF_UNIX,
                    type: STREAM_SOCK_STREAM,
                    protocol: STREAM_IPPROTO_IP,
                );
                \assert(\is_array($pair) && isset($pair[0], $pair[1]));
                $gates[] = new Stream($pair[0]);
                $command = [PHP_BINARY, '-r', "pcntl_alarm(5); fgetc(STDIN); exit({$i});"];
                $workers[$i] = Process::start($group, $command, [0 => $pair[1]]);
                fclose($pair[1]);
            }

            if (!pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $previous)) {
                Assert::fail('Could not block SIGCHLD.');
            }

            /** @var list<int> $previous */
            try {
                foreach ($gates as $gate) {
                    $gate->write('!');
                    $gate->close();
                }

                // Leave every status available to the watcher while all exits share one pending SIGCHLD.
                foreach ($workers as $worker) {
                    Assert::true(pcntl_waitid(P_PID, $worker->pid, $info, WEXITED | WNOWAIT));
                }
            } finally {
                pcntl_sigprocmask(SIG_SETMASK, $previous);
            }

            foreach (array_reverse($workers, preserve_keys: true) as $code => $worker) {
                $status = $worker->wait();
                Assert::same($status->code, $code);
                Assert::null($status->signal);
                Assert::same($worker->wait(), $status);
            }
        });
    }

    public function reportsAStatusTakenByAnotherWaitWhileAPeerIsRunning(): void
    {
        group(static function (Group $group) {
            Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']);
            $worker = Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']);
            $worker->signal(SIGTERM);
            Assert::same(pcntl_waitpid($worker->pid, $status), $worker->pid);

            $error = thrown(static fn() => timeoutOr(sec(2), $worker->wait(...)));
            Assert::instanceOf($error, ProcessException::class);
        });
    }

    public function leavesUnmanagedChildStatusesForTheirOwner(): void
    {
        $child = proc_open([PHP_BINARY, '-r', 'fgetc(STDIN); exit(42);'], [0 => ['pipe', 'r']], $pipes);
        \assert(\is_resource($child) && isset($pipes[0]));
        $pid = proc_get_status($child)['pid'];
        fclose($pipes[0]);
        Assert::true(pcntl_waitid(P_PID, $pid, $info, WEXITED | WNOWAIT));

        try {
            group(static function (Group $group) {
                $worker = Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']);
                $worker->signal(SIGTERM);
                Assert::same(timeoutOr(sec(2), $worker->wait(...))?->signal, SIGTERM);
            });
        } finally {
            $code = proc_close($child);
        }
        Assert::same($code, 42);
    }

    public function oneExitDoesNotWakeTheWholePool(): void
    {
        self::assertPeersStayAsleep(static function (Group $group) {
            for ($i = 0; $i < 7; ++$i) {
                Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']);
            }
        });
    }

    public function oneExitDoesNotWakeOtherGroups(): void
    {
        self::assertPeersStayAsleep(static function (Group $root) {
            for ($i = 0; $i < 7; ++$i) {
                /** @var Deferred<null> $ready */
                $ready = new Deferred();
                $root->daemon(static fn() => group(static function (Group $owner) use ($ready) {
                    $worker = Process::start($owner, [PHP_BINARY, '-r', 'sleep(30);']);
                    $ready->complete();
                    $worker->wait();
                }));
                $ready->await();
            }
        });
    }

    public function closingAnInnerGroupReapsOnlyItsOwnWorkers(): void
    {
        group(static function (Group $outer) {
            [$killed, $survivor] = group(static function (Group $inner) use ($outer) {
                $innerWorker = Process::start($inner, [PHP_BINARY, '-r', 'sleep(30);']);
                $outerWorker = Process::start($outer, [PHP_BINARY, '-r', 'sleep(30);']);
                $outerWorker->signal(SIGTERM);
                Assert::same($outerWorker->wait()->signal, SIGTERM);

                $survivor = Process::start($outer, [PHP_BINARY, '-r', 'sleep(30);']);

                return [$innerWorker, $survivor];
            });

            Assert::same($killed->wait()->signal, SIGKILL);
            $survivor->signal(SIGTERM);
            Assert::same($survivor->wait()->signal, SIGTERM);
        });
    }

    public function distinguishesExit143FromSigterm(): void
    {
        group(static function (Group $group) {
            $exited = Process::start($group, [PHP_BINARY, '-r', 'exit(143);']);
            $signaled = Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']);
            $signaled->signal(SIGTERM);

            Assert::same($signaled->wait()->signal, SIGTERM);
            Assert::null($signaled->wait()->code);
            Assert::same($exited->wait()->code, 143);
            Assert::null($exited->wait()->signal);
        });
    }

    public function cancellingOneWaitLeavesTheWorkerAndOtherWaitersAlive(): void
    {
        group(static function (Group $group) {
            $worker = Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']);
            $other = $group->spawn($worker->wait(...));

            Assert::null(timeoutOr(ms(5), $worker->wait(...)));
            Assert::false($other->isDone);
            $worker->signal(SIGTERM);
            Assert::same($other->await()->signal, SIGTERM);
        });
    }

    public function startingInACancelledGroupThrows(): void
    {
        Expect::exception(TaskCancelled::class);

        group(static function (Group $group) {
            $group->cancelTasks();
            Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']);
        });
    }

    public function restoresTheSignalHandlerWhileTheGroupIsIdle(): void
    {
        $previous = pcntl_signal_get_handler(SIGCHLD);
        $handler = static function () {};
        pcntl_signal(SIGCHLD, $handler);

        try {
            group(static function (Group $group) use ($handler) {
                $error = thrown(static fn() => Process::start($group, [PHP_BINARY, "arg\0tail"]));
                Assert::instanceOf($error, \ValueError::class);
                checkpoint();
                Assert::same(pcntl_signal_get_handler(SIGCHLD), $handler);

                for ($i = 0; $i < 2; ++$i) {
                    $worker = Process::start($group, [PHP_BINARY, '-r', 'exit(7);']);
                    Assert::same($worker->wait()->code, 7);
                    checkpoint();
                    Assert::same(pcntl_signal_get_handler(SIGCHLD), $handler);
                }
            });
        } finally {
            pcntl_signal(SIGCHLD, $previous);
        }
    }

    public function aFailedStartLeavesTheGroupUsable(): void
    {
        group(static function (Group $group) {
            $memory = fopen('php://memory', 'w+');
            \assert(\is_resource($memory));
            $command = [PHP_BINARY, '-r', 'exit(0);'];
            $error = thrown(static fn() => Process::start($group, $command, [3 => $memory]));
            Assert::instanceOf($error, \ValueError::class);

            $error = thrown(static fn() => Process::start($group, [PHP_BINARY, "arg\0tail"]));
            Assert::instanceOf($error, \ValueError::class);

            $worker = Process::start($group, [PHP_BINARY, '-r', 'exit(0);']);
            Assert::same($worker->wait()->code, 0);
            $worker->signal(SIGKILL);
            Assert::same($worker->wait()->code, 0);
        });
    }

    public function groupKillsAndReapsItsLiveWorkerBeforeReturning(): void
    {
        $worker = group(static fn(Group $group) => Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']));

        Assert::same($worker->wait()->signal, SIGKILL);
        Assert::same(pcntl_waitpid($worker->pid, $status, WNOHANG), -1);
        Assert::same(pcntl_get_last_error(), PCNTL_ECHILD);
    }

    /**
     * @param callable(Group): void $startPeers
     */
    private static function assertPeersStayAsleep(callable $startPeers): void
    {
        group(static function (Group $group) use ($startPeers) {
            $created = [];
            $recordScope = static function (TaskCreated|GroupCreated $event) use (&$created) {
                $created[$event->scope] = true;
            };

            // Both observations must share the same trace so a scope keeps its ID.
            observe(new Dispatcher([$recordScope]), static function () use ($group, $startPeers, &$created) {
                $startPeers($group);
                $peers = $created;
                $worker = Process::start($group, [PHP_BINARY, '-r', 'sleep(30);']);

                $resumed = [];
                $recordResume = static function (Resumed $event) use (&$resumed) {
                    $resumed[$event->scope] = true;
                };

                observe(new Dispatcher([$recordResume]), static function () use ($worker) {
                    $worker->signal(SIGTERM);
                    Assert::same($worker->wait()->signal, SIGTERM);
                    checkpoint();
                });

                Assert::same(array_intersect_key($resumed, $peers), []);
            });
        });
    }
}
