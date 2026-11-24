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
        // SIGCHLD can cover several exits, including a child already reaped by another wait.
        foreach (self::$byPid as $child) {
            $child->refresh();
        }
    }
}
