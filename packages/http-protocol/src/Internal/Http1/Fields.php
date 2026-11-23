<?php

declare(strict_types=1);

namespace Thesis\HttpProtocol\Internal\Http1;

use Thesis\Http\Headers;
use Thesis\Http\Status;
use Thesis\HttpProtocol\Http1\Framing;
use Thesis\HttpProtocol\Http1\MalformedMessage;
use Thesis\HttpProtocol\Http1\Version;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * The field lines of a head, parsed strictly. The fields that frame the message are separated from the headers.
 *
 * @internal
 */
final readonly class Fields
{
    /**
     * The fields that belong to an HTTP/1.1 connection, not to a message.
     */
    public const array OF_CONNECTION = ['content-length', 'transfer-encoding', 'connection', 'keep-alive', 'upgrade'];

    /**
     * Trailers must not frame or route a message: a recipient that merges trailers into the headers
     * would be fooled.
     */
    public const array NOT_IN_TRAILERS = [...self::OF_CONNECTION, 'host'];

    /**
     * A decimal number with more digits does not fit into an int.
     */
    private const int MAX_LENGTH_DIGITS = 18;

    /**
     * @param list<string> $lines
     * @param positive-int $maxFields
     * @throws MalformedMessage
     */
    public static function parse(array $lines, Version $version, int $maxFields): self
    {
        $fields = self::lines($lines, $maxFields);
        $contentLength = isset($fields['content-length']) ? self::contentLength($fields['content-length']) : null;
        $isChunked = isset($fields['transfer-encoding']) && self::isChunked($fields['transfer-encoding'], $version);

        if ($isChunked && $contentLength !== null) {
            throw new MalformedMessage('A message must not have both Content-Length and Transfer-Encoding.');
        }

        $isPersistent = $version === Version::Http11;
        $upgrade = [];

        if (isset($fields['connection'])) {
            $options = self::options($fields['connection']);
            $isPersistent = ($isPersistent || \in_array('keep-alive', $options, strict: true))
                && !\in_array('close', $options, strict: true);

            if (isset($fields['upgrade']) && \in_array('upgrade', $options, strict: true)) {
                $upgrade = self::list($fields['upgrade']);

                try {
                    self::assertUpgrade($upgrade);
                } catch (\ValueError $error) {
                    throw new MalformedMessage($error->getMessage());
                }
            }

            foreach ($options as $name) {
                unset($fields[$name]);
            }
        }

        foreach (self::OF_CONNECTION as $name) {
            unset($fields[$name]);
        }

        return new self(
            endToEnd: $fields,
            contentLength: $contentLength,
            isChunked: $isChunked,
            isPersistent: $isPersistent,
            upgrade: $upgrade,
        );
    }

    /**
     * @param array<non-empty-lowercase-string, non-empty-list<string>> $endToEnd the lines by lowercase name, without the fields
     *     that belong to the connection and the fields that `Connection` names
     * @param ?non-negative-int $contentLength
     * @param list<non-empty-string> $upgrade the protocols from `Upgrade`, empty unless `Connection` has `upgrade`
     */
    private function __construct(
        public array $endToEnd,
        public ?int $contentLength,
        public bool $isChunked,
        public bool $isPersistent,
        public array $upgrade,
    ) {}

    public function toHeaders(bool $withoutHost = false): Headers
    {
        $lines = $this->endToEnd;
        if ($withoutHost) {
            unset($lines['host']);
        }

        return $lines === [] ? EMPTY_HEADERS : new Headers($lines);
    }

    public static function hasTrailers(Headers $headers, bool $mayHaveTrailers): bool
    {
        return $mayHaveTrailers || $headers->has('trailer');
    }

    /**
     * @param list<non-empty-string> $protocols
     */
    public static function assertUpgrade(array $protocols): void
    {
        foreach ($protocols as $protocol) {
            $parts = explode('/', $protocol, limit: 2);

            if (!isToken($parts[0]) || (isset($parts[1]) && !isToken($parts[1]))) {
                throw new \ValueError(\sprintf(
                    'An Upgrade protocol must be a token with an optional token version, got %s.',
                    quote($protocol),
                ));
            }
        }
    }

    /**
     * Checks the fields supplied by the application. An eligible HEAD or 304 may include the representation length.
     *
     * @throws \ValueError
     */
    public static function assertEndToEnd(Headers $headers, bool $allowContentLength = false): void
    {
        $lines = $headers->toArray();

        foreach (self::OF_CONNECTION as $name) {
            if (!isset($lines[$name])) {
                continue;
            }

            if ($name !== 'content-length' || !$allowContentLength) {
                throw new \ValueError(\sprintf(
                    'The header "%s" belongs to an HTTP/1.1 connection and is written by the codec.',
                    $name,
                ));
            }

            try {
                self::contentLength($lines[$name]);
            } catch (MalformedMessage $error) {
                throw new \ValueError($error->getMessage(), previous: $error);
            }
        }
    }

    /**
     * The field lines of a head: the headers first, then the fields that belong to the connection.
     *
     * @param ?non-negative-int $length the number of bytes in the body when known
     * @param list<non-empty-string> $upgrade
     */
    public static function format(
        Headers $headers,
        Version $version,
        Framing $framing,
        ?int $length,
        bool $isPersistent,
        array $upgrade,
    ): string {
        $bytes = self::formatLines($headers);

        if ($framing === Framing::Length) {
            $bytes .= "Content-Length: {$length}\r\n";
        } elseif ($framing === Framing::Chunked) {
            $bytes .= "Transfer-Encoding: chunked\r\n";
        }

        // Only the choice that is not the default is written: HTTP/1.1 keeps a connection open, HTTP/1.0 closes it.
        $options = match ($version) {
            Version::Http10 => $isPersistent ? ['keep-alive'] : [],
            Version::Http11 => $isPersistent ? [] : ['close'],
        };

        if ($upgrade !== []) {
            $options[] = 'upgrade';
            $bytes .= 'Upgrade: ' . implode(', ', $upgrade) . "\r\n";
        }

        if ($options !== []) {
            $bytes .= 'Connection: ' . implode(', ', $options) . "\r\n";
        }

        return $bytes . "\r\n";
    }

    public static function formatLines(Headers $headers): string
    {
        $bytes = '';

        foreach ($headers->toArray() as $name => $values) {
            $name = ucwords((string) $name, '-');

            foreach ($values as $value) {
                $bytes .= "{$name}: {$value}\r\n";
            }
        }

        return $bytes;
    }

    /**
     * @param list<string> $lines
     * @param positive-int $maxFields
     * @return array<non-empty-lowercase-string, non-empty-list<string>>
     * @throws MalformedMessage
     */
    public static function lines(array $lines, int $maxFields): array
    {
        self::checkCount(\count($lines), $maxFields);

        $fields = [];

        foreach ($lines as $line) {
            $name = strstr($line, ':', before_needle: true);

            // A line that starts with a space (obs-fold) or has a space before the colon fails this check too.
            if ($name === false || !isToken($name)) {
                throw new MalformedMessage(\sprintf(
                    'A field line must be a token, a colon and a value, got %s.',
                    quote($line),
                ));
            }

            $value = trim(substr($line, \strlen($name) + 1), " \t");

            if (!isFieldValue($value)) {
                throw new MalformedMessage(\sprintf(
                    'A value of the field "%s" must have no control characters, got %s.',
                    $name,
                    quote($value),
                ));
            }

            $fields[strtolower($name)][] = $value;
        }

        return $fields;
    }

    /**
     * @throws MalformedMessage
     */
    public static function checkCount(int $count, int $maxFields): void
    {
        if ($count > $maxFields) {
            throw new MalformedMessage(
                message: \sprintf('A message must have at most %d fields, got %d.', $maxFields, $count),
                status: Status::REQUEST_HEADER_FIELDS_TOO_LARGE,
            );
        }
    }

    /**
     * @param list<string> $values
     * @return ?non-negative-int
     * @throws MalformedMessage
     */
    private static function contentLength(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        // Not (int), intval() or is_numeric(): they accept a sign, spaces, 0x and a list "5, 5".
        if (
            \count($values) > 1
            || $values[0] === ''
            || \strlen($values[0]) > self::MAX_LENGTH_DIGITS
            || strspn($values[0], '0123456789') !== \strlen($values[0])
        ) {
            throw new MalformedMessage(\sprintf(
                'Content-Length must be one number of at most %d digits, got %s.',
                self::MAX_LENGTH_DIGITS,
                quote(implode(', ', $values)),
            ));
        }

        /** @var non-negative-int */
        return (int) $values[0];
    }

    /**
     * @param list<string> $values
     * @throws MalformedMessage
     */
    private static function isChunked(array $values, Version $version): bool
    {
        if ($values === []) {
            return false;
        }

        if ($version === Version::Http10) {
            throw new MalformedMessage('An HTTP/1.0 message must not have Transfer-Encoding.');
        }

        if (\count($values) > 1 || strtolower($values[0]) !== 'chunked') {
            throw new MalformedMessage(
                message: \sprintf('Transfer-Encoding must be "chunked", got %s.', quote(implode(', ', $values))),
                status: Status::NOT_IMPLEMENTED,
            );
        }

        return true;
    }

    /**
     * @param list<string> $values
     * @return list<lowercase-string>
     * @throws MalformedMessage
     */
    private static function options(array $values): array
    {
        $options = [];

        foreach (self::list($values) as $option) {
            if (!isToken($option)) {
                throw new MalformedMessage(\sprintf('A Connection option must be a token, got %s.', quote($option)));
            }

            $options[] = strtolower($option);
        }

        return $options;
    }

    /**
     * Splits the lines of a comma-separated field into its elements. Empty elements are dropped.
     *
     * @param list<string> $values
     * @return list<non-empty-string>
     */
    private static function list(array $values): array
    {
        $elements = [];

        foreach ($values as $value) {
            foreach (explode(',', $value) as $element) {
                $element = trim($element, " \t");

                if ($element !== '') {
                    $elements[] = $element;
                }
            }
        }

        return $elements;
    }
}
