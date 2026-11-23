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
        $fields = [];

        foreach (\array_slice($context, 0, self::MAX_FIELDS, preserve_keys: true) as $key => $value) {
            if (\strlen((string) $key) > self::MAX_KEY_BYTES || str_starts_with((string) $key, "\0")) {
                continue;
            }

            $fields[$key] = self::field($key, $value);
        }

        $text = self::text((string) $message);

        return new Record(
            time: $time,
            level: $level,
            message: self::text(MessageInterpolator::interpolate($text, $fields)),
            context: $fields,
        );
    }

    /**
     * @return scalar|null|array{type: string, message: string}
     */
    private static function field(int|string $key, mixed $value): null|int|float|bool|string|array
    {
        return match (true) {
            $key === 'exception' && $value instanceof \Throwable => [
                'type' => self::text($value::class),
                'message' => self::text($value->getMessage()),
            ],
            \is_string($value) => self::text($value),
            \is_float($value) && !is_finite($value) => null,
            \is_scalar($value), $value === null => $value,
            default => self::text('[' . get_debug_type($value) . ']'),
        };
    }

    private static function text(string $value): string
    {
        return substr($value, 0, self::MAX_TEXT_BYTES);
    }
}
