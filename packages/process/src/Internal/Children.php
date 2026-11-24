<?php

declare(strict_types=1);

namespace Thesis\Process\Internal;

use Thesis\Process\Process;
use function Thesis\Async\onSignal;

/**
 * A process-wide PID index. A nested event loop can collect a status before the outer loop resumes.
 *
 * @internal
 */
final class Children
{
    /**
     * @var array<int, Process>
     */
    private static array $byPid = [];

    /**
     * @return \Closure(): void removes this owner's subscription in its event loop
     */
    public static function subscribe(): \Closure
    {
        /** @var \Closure(): void $collect */
        static $collect = self::collect(...);

        return onSignal(SIGCHLD, $collect);
    }

    public static function add(Process $child): void
    {
        self::$byPid[$child->pid] = $child;
    }

    public static function remove(int $pid): void
    {
        unset(self::$byPid[$pid]);
    }

    private static function collect(): void
    {
        while (self::$byPid !== []) {
            // WNOWAIT leaves the status for proc_get_status(), including a foreign child's status for its owner.
            if (!pcntl_waitid(idtype: P_ALL, id: 0, info: $info, flags: WEXITED | WNOHANG | WNOWAIT)) {
                if (pcntl_get_last_error() === PCNTL_EINTR) {
                    continue;
                }

                break;
            }

            /** @var array{pid: int} $info */
            $pid = $info['pid'];
            if ($pid === 0) {
                return;
            }

            $child = self::$byPid[$pid] ?? null;
            if ($child === null) {
                break;
            }

            $child->refresh();
        }

        // A foreign zombie can stay at the head of waitid(P_ALL) until its owner reaps it.
        foreach (self::$byPid as $child) {
            $child->refresh();
        }
    }
}
