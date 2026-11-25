<?php

declare(strict_types=1);

namespace Thesis\Logger\Internal;

use Psr\Log\LogLevel;
use Thesis\Logger\Record;

/**
 * Builds a record with normalized PSR-3 context, including an exception under the `exception` key.
 *
 * @internal
 * @see https://www.php-fig.org/psr/psr-3/#13-context
 */
final class RecordFactory
{
    private const int MAX_FIELDS = 32;
    private const int MAX_KEY_BYTES = 128;
    private const int MAX_TEXT_BYTES = 1024;

    /**
     * @param LogLevel::* $level
     * @param array<array-key, mixed> $context
     */
    public static function capture(
        \DateTimeImmutable $time,
        string $level,
        string|\Stringable $message,
        array $context,
    ): Record {
        $time = \DateTimeImmutable::createFromInterface($time);
        $fields = [];

        foreach (\array_slice($context, 0, self::MAX_FIELDS, preserve_keys: true) as $key => $value) {
            $name = (string) $key;

            if (
                \strlen($name) > self::MAX_KEY_BYTES
                || str_starts_with($name, "\0")
                || preg_match('//u', $name) !== 1
            ) {
                continue;
            }

            $fields[$key] = self::field($key, $value);
        }

        [$text, $keys] = MessageInterpolator::interpolate(self::text((string) $message), $fields);

        return new Record(
            time: $time,
            level: $level,
            message: self::text($text),
            context: $fields,
            interpolatedKeys: $keys,
        );
    }

    /**
     * @return scalar|null|array{type: string, message: string, file: string, line: int}
     */
    private static function field(int|string $key, mixed $value): null|int|float|bool|string|array
    {
        return match (true) {
            $key === 'exception' && $value instanceof \Throwable => self::exception($value),
            \is_string($value) => self::text($value),
            \is_float($value) && !is_finite($value) => null,
            \is_scalar($value), $value === null => $value,
            default => self::typeSummary($value),
        };
    }

    /**
     * @return array{type: string, message: string, file: string, line: int}
     */
    private static function exception(\Throwable $error): array
    {
        $message = self::rawProperty($error, 'message');
        $file = self::rawProperty($error, 'file');
        $line = self::rawProperty($error, 'line');

        return [
            'type' => self::text($error::class),
            'message' => \is_scalar($message) || $message === null
                ? self::text((string) $message)
                : self::typeSummary($message),
            'file' => \is_string($file) ? self::text($file) : '',
            'line' => \is_int($line) ? $line : 0,
        ];
    }

    // Even the final throwable getters can call property hooks, __get() or a message object's __toString().
    private static function rawProperty(\Throwable $error, string $name): mixed
    {
        $property = new \ReflectionProperty($error, $name);

        return $property->isInitialized($error) ? $property->getRawValue($error) : null;
    }

    private static function typeSummary(mixed $value): string
    {
        return self::text('[' . get_debug_type($value) . ']');
    }

    private static function text(string $value): string
    {
        return substr($value, 0, self::MAX_TEXT_BYTES);
    }
}
