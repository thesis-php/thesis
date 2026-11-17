<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace\Internal;

use Time\Duration;

/**
 * @internal
 */
final readonly class Seconds
{
    /**
     * The exact decimal of the seconds, without trailing zeros: `0`, `1.5`, `1.000000001`, `-1.5`.
     *
     * @return non-empty-string
     */
    public static function format(Duration $duration): string
    {
        $sign = $duration->negative ? '-' : '';

        if ($duration->nanoseconds === 0) {
            return $sign . $duration->seconds;
        }

        return $sign . $duration->seconds . '.' . rtrim(\sprintf('%09d', $duration->nanoseconds), '0');
    }
}
