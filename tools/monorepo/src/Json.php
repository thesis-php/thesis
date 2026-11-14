<?php

declare(strict_types=1);

namespace Thesis\Monorepo;

/**
 * @internal
 */
final readonly class Json
{
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function decode(string $content, string $context): array
    {
        try {
            $value = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MonorepoException(\sprintf('%s: %s.', $context, $exception->getMessage()), previous: $exception);
        }

        if (!\is_array($value)) {
            throw new MonorepoException(\sprintf('%s: expected an object.', $context));
        }

        return $value;
    }

    /**
     * Order-insensitive form of a JSON object, for comparing by meaning.
     */
    public static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
