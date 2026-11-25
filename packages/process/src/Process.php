<?php

declare(strict_types=1);

namespace Thesis;

use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\TaskCancelled;
use Thesis\Process\ExitStatus;
use Thesis\Process\Internal\Children;
use Thesis\Process\Internal\Descriptors;
use Thesis\Process\ProcessException;
use function Thesis\Async\checkpoint;
use function Thesis\Async\shield;

/**
 * A direct child owned by a group. Ending the group kills and reaps a child that is still running.
 *
 * @api
 */
final class Process
{
    /**
     * @param non-empty-list<string> $command executable followed by its arguments
     * @param array<non-negative-int, resource> $descriptors child descriptor numbers mapped to the parent's streams.
     *     The caller keeps ownership. By default the child inherits standard input, output and error.
     * @param ?array<string, string> $env the whole child environment, or null to inherit the parent's
     * @throws \ValueError if an argument contains a null byte or a descriptor is invalid
     * @throws \LogicException if the group has closed
     * @throws ProcessException if the operating system cannot start the child
     * @throws TaskCancelled if the group's daemons were already cancelled
     */
    public static function start(
        Group $group,
        array $command,
        array $descriptors = [],
        ?string $cwd = null,
        ?array $env = null,
    ): self {
        /** @var Deferred<self> $started */
        $started = new Deferred();
        $launch = static fn() => self::open($command, $descriptors, $cwd, $env);
        $owner = $group->daemon(static function () use ($started, &$launch) {
            $unsubscribe = Children::subscribe();

            try {
                try {
                    \assert($launch !== null);
                    $child = $launch();
                } catch (\Throwable $error) {
                    $started->fail($error);

                    return;
                } finally {
                    // A long-lived worker must not keep the caller's copies of its inherited descriptors open.
                    $launch = null;
                }

                $started->complete($child);
                $child->own();
            } finally {
                $unsubscribe();
            }
        });

        try {
            // A group may cancel the daemon before its callable starts, leaving the startup result unset.
            while (!$started->isDone) {
                if ($owner->isDone) {
                    $owner->await();
                }
                checkpoint();
            }

            return $started->await();
        } catch (\Throwable $error) {
            $owner->cancel();

            throw $error;
        }
    }

    /**
     * @param non-empty-list<string> $command
     * @param array<non-negative-int, resource> $descriptors
     * @param ?array<string, string> $env
     */
    private static function open(array $command, array $descriptors, ?string $cwd, ?array $env): self
    {
        $environment = null;
        if ($env !== null) {
            $environment = [];
            foreach ($env as $name => $value) {
                $environment[] = "{$name}={$value}";
            }
        }

        try {
            $spec = Descriptors::prepare($descriptors);
            $handle = exceptionally(static fn() => proc_open(
                command: $command,
                descriptor_spec: $spec,
                pipes: $pipes,
                cwd: $cwd,
                // @phpstan-ignore argument.type (Unix proc_open accepts a list of name=value entries.)
                env_vars: $environment,
            ));
        } catch (\ErrorException $error) {
            throw new ProcessException($error->getMessage(), previous: $error);
        }

        $status = proc_get_status($handle);
        $child = new self($handle, $status['pid']);
        Children::add($child);
        $child->record($status);

        return $child;
    }

    /**
     * @var resource|null
     */
    private mixed $handle;

    /**
     * @var Deferred<ExitStatus|ProcessException>
     */
    private readonly Deferred $exit;

    /**
     * @param resource $handle
     */
    private function __construct(
        mixed $handle,
        public readonly int $pid,
    ) {
        $this->handle = $handle;
        $this->exit = new Deferred();
    }

    private function __clone() {}

    /**
     * Cancelling a wait leaves the child running and other waiters attached.
     *
     * @throws ProcessException if another wait consumed the exit status
     */
    public function wait(): ExitStatus
    {
        $status = $this->exit->await();
        if ($status instanceof ProcessException) {
            throw $status;
        }

        return $status;
    }

    /**
     * Sends a signal to this child. Has no effect after it exits.
     *
     * @throws ProcessException if the operating system refuses the signal
     */
    public function signal(int $signal): void
    {
        if ($this->handle === null) {
            return;
        }

        try {
            exceptionally(fn() => proc_terminate($this->handle, $signal));
        } catch (\ErrorException $error) {
            $this->refresh();

            if ($this->handle !== null) {
                throw new ProcessException($error->getMessage(), previous: $error);
            }
        }
    }

    private function own(): void
    {
        try {
            $this->exit->await();
        } finally {
            if ($this->handle !== null) {
                $this->signal(SIGKILL);
                $this->refresh();
                shield($this->exit->await(...));
            }
        }
    }

    /**
     * @internal
     */
    public function refresh(): void
    {
        if ($this->handle !== null) {
            $this->record(proc_get_status($this->handle));
        }
    }

    /**
     * @param array{running: bool, signaled: bool, termsig: int, exitcode: int} $status
     */
    private function record(array $status): void
    {
        if ($status['running']) {
            return;
        }

        \assert($this->handle !== null);
        proc_close($this->handle);
        $this->handle = null;
        Children::remove($this->pid);
        if (!$status['signaled'] && $status['exitcode'] < 0) {
            $this->exit->complete(new ProcessException("The exit status of process {$this->pid} is unavailable."));

            return;
        }

        $this->exit->complete($status['signaled']
            ? new ExitStatus(code: null, signal: $status['termsig'])
            : new ExitStatus(code: $status['exitcode'], signal: null));
    }
}
