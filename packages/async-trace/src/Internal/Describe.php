<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace\Internal;

use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\ShieldCreated;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Async\Trace\TimeoutCreated;
use Thesis\SourceLocation\Location;
use function Thesis\Formatter\formatValue;

/**
 * A scope and an error as the reports of the tools name them: `task 'fetch' at /app/src/main.php:12`.
 *
 * @internal
 */
final readonly class Describe
{
    /**
     * @return non-empty-string
     */
    public static function scope(GroupCreated|TaskCreated|TimeoutCreated|ShieldCreated $event): string
    {
        /** @var non-empty-string $what */
        $what = match (true) {
            $event instanceof GroupCreated => str_replace('Thesis\Async\\', '', $event->function)
                . self::name($event->name),
            $event instanceof TaskCreated => ($event->daemon ? 'daemon' : 'task') . self::name($event->name),
            $event instanceof TimeoutCreated => 'timeout ' . Seconds::format($event->duration) . 's',
            $event instanceof ShieldCreated => 'shield',
        };

        return self::at($what, $event->location);
    }

    /**
     * @return non-empty-string
     */
    public static function error(\Throwable $error): string
    {
        return $error::class . ' ' . formatValue($error->getMessage())
            . ' at ' . $error->getFile() . ':' . $error->getLine();
    }

    private static function name(?string $name): string
    {
        return $name === null ? '' : ' ' . formatValue($name);
    }

    /**
     * @param non-empty-string $what
     * @return non-empty-string
     */
    private static function at(string $what, ?Location $location): string
    {
        return $location === null ? $what : $what . ' at ' . $location;
    }
}
