<?php

declare(strict_types=1);

namespace Thesis\HotReload\Internal;

use Thesis\Async\Channel;
use Thesis\Async\Group;
use Thesis\Process\Process;
use Thesis\Process\ProcessException;
use function Thesis\Async\group;
use function Thesis\Async\onCancel;
use function Thesis\Async\shield;
use function Thesis\Async\timeoutOr;
use function Thesis\Io\stderr;
use function Thesis\Time\sec;

/**
 * @internal
 */
final class Supervisor
{
    private bool $started = false;

    /**
     * @param non-empty-list<string> $command
     * @param Channel<true> $reload
     */
    public function __construct(
        private readonly array $command,
        private readonly Channel $reload,
        private readonly Watcher $watcher,
    ) {}

    public function reload(): void
    {
        $this->reload->trySend(true);
    }

    public function run(Group $group): void
    {
        if ($this->started) {
            throw new \LogicException('The supervisor has already started.');
        }

        $this->started = true;
        $this->watcher->run($group);
        $this->reload();

        $group->daemon(function () {
            onCancel($this->reload->close(...));

            // Keep the worker's owner alive until graceful shutdown has reaped the worker.
            shield(fn() => group($this->supervise(...)));
        });
    }

    private function supervise(Group $group): void
    {
        stderr()->write('Supervisor ' . getmypid() . " is listening for SIGHUP.\n");

        $worker = null;

        try {
            foreach ($this->reload as $_) {
                if ($worker !== null) {
                    self::stop($worker);
                    $worker = null;
                }
                if ($this->reload->isClosed) {
                    break;
                }

                // Include reloads requested while the previous worker was stopping.
                $this->reload->tryReceive();
                $this->watcher->refresh();

                try {
                    $worker = Process::start($group, $this->command);
                } catch (ProcessException $error) {
                    stderr()->write("Could not start the command. {$error->getMessage()}\n");

                    continue;
                }

                // Reporting the exit independently lets the same signal receiver survive every worker.
                $group->spawn(static function () use ($worker) {
                    $status = $worker->wait();
                    $result = $status->signal === null ? "code {$status->code}" : "signal {$status->signal}";
                    stderr()->write("Process {$worker->pid} exited with {$result}.\n");
                });
            }
        } finally {
            if ($worker !== null) {
                shield(static fn() => self::stop($worker));
            }
        }
    }

    private static function stop(Process $worker): void
    {
        $worker->signal(SIGTERM);
        if (timeoutOr(sec(3), $worker->wait(...)) === null) {
            $worker->signal(SIGKILL);
            $worker->wait();
        }
    }
}
