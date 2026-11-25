<?php

declare(strict_types=1);

namespace Thesis\Logger\Internal;

use Thesis\Logger\Record;

/**
 * Replaces PSR-3 message placeholders with matching values from the normalized context.
 *
 * @internal
 * @see https://www.php-fig.org/psr/psr-3/#12-message
 * @phpstan-import-type Context from Record
 */
final class MessageInterpolator
{
    /**
     * @param Context $context
     * @return array{string, list<array-key>} the message and the context keys it used
     */
    public static function interpolate(string $message, array $context): array
    {
        if (!str_contains($message, '{')) {
            // Without placeholders, building the replacement table produces the same message.
            // @infection-ignore ReturnRemoval
            return [$message, []];
        }

        $replacements = [];
        $keys = [];

        foreach ($context as $key => $value) {
            $name = (string) $key;
            if (\is_array($value) || $name === '' || strcspn($name, "{} \t\r\n\v\f") !== \strlen($name)) {
                continue;
            }

            $placeholder = '{' . $name . '}';
            if (!str_contains($message, $placeholder)) {
                continue;
            }

            $keys[] = $key;
            $replacements[$placeholder] = match ($value) {
                true => 'true',
                false => 'false',
                null => 'null',
                default => $value,
            };
        }

        return [strtr($message, $replacements), $keys];
    }
}
