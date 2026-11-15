<?php

declare(strict_types=1);

namespace Thesis\Time;

use Testo\Assert;
use Time\Duration;

function assertDuration(Duration $actual, int $seconds, int $nanoseconds = 0, bool $negative = false): void
{
    Assert::same(
        actual: [$actual->seconds, $actual->nanoseconds, $actual->negative],
        expected: [$seconds, $nanoseconds, $negative],
    );
}

function signed(int $seconds, int $nanoseconds = 0, bool $negative = false): Duration
{
    $duration = Duration::fromSeconds($seconds, $nanoseconds);

    return $negative ? $duration->negate() : $duration;
}

/**
 * The outcome of a call as a string: "90.5" or "-0.25" for a duration of either implementation,
 * "Time\TimeException: message" for an exception, the class of the polyfill copy named like the original.
 *
 * @param callable(): mixed $call
 */
function outcome(callable $call): string
{
    try {
        return describe($call());
    } catch (\Throwable $exception) {
        $previous = $exception->getPrevious();

        return \sprintf(
            '%s: %s%s',
            str_replace('Thesis\Time\Polyfill\\', 'Time\\', $exception::class),
            $exception->getMessage(),
            $previous === null ? '' : ' < ' . str_replace('Thesis\Time\Polyfill\\', 'Time\\', $previous::class),
        );
    }
}

function describe(mixed $value): string
{
    if (\is_array($value)) {
        return '[' . implode(', ', array_map(describe(...), $value)) . ']';
    }

    if (\is_object($value) && property_exists($value, 'negative')) {
        /** @var object{seconds: int, nanoseconds: int, negative: bool} $value */
        return \sprintf(
            '%s%d.%s',
            $value->negative ? '-' : '',
            $value->seconds,
            str_pad((string) $value->nanoseconds, 9, '0', STR_PAD_LEFT),
        );
    }

    return var_export($value, true);
}

/**
 * Whether Time\Duration is the class of PHP 8.6, not the polyfill.
 */
function isNative(): bool
{
    return new \ReflectionClass(Duration::class)->isInternal();
}

/**
 * Loads a copy of the polyfill as Thesis\Time\Polyfill\Duration, to compare it with the class of PHP 8.6
 * in one process: the original cannot be loaded there under its own name.
 *
 * @return class-string<Duration> the copy has the API of Duration, not its type
 */
function loadPolyfillCopy(): string
{
    $copy = 'Thesis\Time\Polyfill\Duration';

    if (!class_exists($copy, autoload: false)) {
        foreach (['TimeException', 'Duration'] as $class) {
            $code = file_get_contents(\sprintf('%s/polyfill/%s.php', \dirname(__DIR__), $class));
            \assert($code !== false);
            $file = tempnam(sys_get_temp_dir(), 'thesis-time-polyfill-');
            \assert($file !== false);
            file_put_contents($file, str_replace('namespace Time;', 'namespace Thesis\Time\Polyfill;', $code));

            try {
                require $file;
            } finally {
                unlink($file);
            }
        }
    }

    /** @phpstan-ignore return.type */
    return $copy;
}
