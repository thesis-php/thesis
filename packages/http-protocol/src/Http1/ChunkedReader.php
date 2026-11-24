<?php

declare(strict_types=1);

namespace Thesis\HttpProtocol\Http1;

use Thesis\Http\AbstractBody;
use Thesis\Http\Headers;
use Thesis\Http\Status;
use Thesis\HttpProtocol\Internal\Http1\Fields;
use Thesis\Io\Buffer;
use Thesis\Io\ConcurrentRead;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use function Thesis\HttpProtocol\Internal\Http1\quote;
use const Thesis\Http\EMPTY_HEADERS;
use const Thesis\HttpProtocol\Internal\Http1\TOKEN_CHARS;

/**
 * Reads a chunked body through the last chunk and its trailers.
 * Buffered bytes after the trailers belong to the next message. Closing the body leaves the connection open.
 *
 * If the connection ends earlier, the read fails with {@see ReadFailed}: a truncated body must not look complete.
 *
 * @api
 */
final class ChunkedReader extends AbstractBody
{
    #[\Override]
    public ?int $size {
        get => null;
    }

    /**
     * Any 15-digit hexadecimal size fits in a signed 64-bit integer.
     */
    private const int MAX_SIZE_DIGITS = 15;

    #[\Override]
    public bool $isBuffered {
        get => false;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => $this->trailers === null || !$this->trailers->isEmpty;
    }

    /**
     * The reader has consumed the last chunk and trailer section.
     */
    public bool $isComplete {
        get => $this->trailers !== null;
    }

    /**
     * @var non-negative-int unread bytes in the current chunk
     */
    private int $remaining = 0;

    private bool $hasPendingChunkTerminator = false;

    private bool $isLastChunkRead = false;

    /**
     * @var list<string>
     */
    private array $trailerLines = [];

    /**
     * @var non-negative-int
     */
    private int $trailersLength = 0;

    /**
     * The trailer fields, or null until the last chunk and the trailer section have been read.
     */
    private ?Headers $trailers = null;

    private ?MalformedMessage $failure = null;

    private bool $isReading = false;

    /**
     * @param positive-int $maxSizeLineLength bytes including chunk extensions and CRLF
     * @param positive-int $maxTrailersLength bytes including the final empty line
     * @param positive-int $maxTrailers
     */
    public function __construct(
        private readonly Buffer $buffer,
        private readonly Reader $reader,
        private readonly int $maxSizeLineLength = 256,
        private readonly int $maxTrailersLength = 16 * 1024,
        private readonly int $maxTrailers = 100,
    ) {}

    private function __clone() {}

    /**
     * Invalid chunk framing or trailers cause MalformedMessage on this and every later read.
     */
    public function read(int $max): ?string
    {
        if ($this->isReading) {
            throw new ConcurrentRead('A read is already in progress on the body.');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->isReading = true;

        try {
            return $this->readChunk($max);
        } catch (MalformedMessage $error) {
            $this->failure = $error;

            throw $error;
        } finally {
            $this->isReading = false;
        }
    }

    public function discard(): Headers
    {
        while (true) {
            if ($this->trailers !== null) {
                return $this->trailers;
            }

            $this->read(8192);
        }
    }

    public function close(): void {}

    /**
     * @param positive-int $max
     * @return ?non-empty-string
     */
    private function readChunk(int $max): ?string
    {
        if ($this->remaining === 0) {
            $this->remaining = $this->nextChunk();
            if ($this->remaining === 0) {
                return null;
            }
        }

        $length = min($max, $this->remaining);
        $buffered = $this->buffer->count();

        $bytes = $buffered > 0 ? $this->buffer->read(min($length, $buffered)) : $this->reader->read($length);
        if ($bytes === null) {
            throw self::cutShort(new UnexpectedEof());
        }

        /** @var non-negative-int $remaining a reader gives at most the bytes it is asked for */
        $remaining = $this->remaining - \strlen($bytes);
        $this->remaining = $remaining;

        return $bytes;
    }

    private static function cutShort(UnexpectedEof $eof): ReadFailed
    {
        return new ReadFailed('The connection has ended before the last chunk of the body.', previous: $eof);
    }

    /**
     * Every step changes the state only when it is complete, so a cancelled read continues from the same step.
     *
     * @return non-negative-int the size of the next chunk, zero after the trailers
     * @throws ReadFailed
     * @throws MalformedMessage
     */
    private function nextChunk(): int
    {
        if ($this->trailers !== null) {
            return 0;
        }

        if (!$this->isLastChunkRead) {
            if ($this->hasPendingChunkTerminator) {
                // A buffered last chunk without extensions or trailers ends the body in one step.
                if ($this->maxSizeLineLength >= 3 && $this->maxTrailersLength >= 2
                    && $this->buffer->count() >= 7 && $this->buffer->peek(7) === "\r\n0\r\n\r\n") {
                    $this->buffer->skip(7);
                    $this->trailers = EMPTY_HEADERS;

                    return 0;
                }

                $this->readEndOfChunk();
                $this->hasPendingChunkTerminator = false;
            }

            $size = $this->readChunkSize();

            if ($size > 0) {
                $this->hasPendingChunkTerminator = true;

                return $size;
            }

            $this->isLastChunkRead = true;
        }

        $this->trailers = $this->readTrailers();

        return 0;
    }

    /**
     * @throws ReadFailed
     * @throws MalformedMessage
     */
    private function readEndOfChunk(): void
    {
        try {
            // Asking for 3 bytes would work as well: a chunk size line always follows the CRLF.
            // @infection-ignore IncrementInteger
            $this->buffer->fill($this->reader, 2);
        } catch (UnexpectedEof $eof) {
            throw self::cutShort($eof);
        }

        if ($this->buffer->peek(2) !== "\r\n") {
            throw new MalformedMessage(\sprintf(
                'The data of a chunk must be followed by CRLF, got %s.',
                quote($this->buffer->peek(2)),
            ));
        }

        $this->buffer->skip(2);
    }

    /**
     * @return non-negative-int
     * @throws ReadFailed
     * @throws MalformedMessage
     */
    private function readChunkSize(): int
    {
        $line = $this->readLine($this->maxSizeLineLength) ?? throw new MalformedMessage(\sprintf(
            'A chunk size line must be at most %d bytes.',
            $this->maxSizeLineLength,
        ));
        $digits = strspn($line, '0123456789abcdefABCDEF');
        $extensions = substr($line, $digits);

        // hexdec() silently skips characters that are not hex digits, and returns a float for a longer number.
        if (
            $digits === 0
            || $digits > self::MAX_SIZE_DIGITS
            || ($extensions !== '' && !self::validExtensions($extensions))
        ) {
            throw new MalformedMessage(\sprintf(
                'A chunk size must be a hexadecimal number of at most %d digits, got %s.',
                self::MAX_SIZE_DIGITS,
                quote($line),
            ));
        }

        /** @var non-negative-int */
        return (int) hexdec(substr($line, 0, $digits));
    }

    private static function validExtensions(string $extensions): bool
    {
        $token = '[' . preg_quote(TOKEN_CHARS, '/') . ']++';
        // RFC 9110 quoted-string: ordinary text excludes double quote and backslash, a quoted-pair escapes either.
        $quoted = '"(?:[\t !\x23-\x5b\x5d-\x7e\x80-\xff]++|\x5c[\t\x20-\x7e\x80-\xff])*+"';
        $value = "(?:{$token}|{$quoted})";

        $pattern = "/\\A(?:[ \\t]*+;[ \\t]*+{$token}(?:[ \\t]*+=[ \\t]*+{$value})?+)++\\z/";

        return preg_match($pattern, $extensions) === 1;
    }

    /**
     * @throws ReadFailed
     * @throws MalformedMessage
     */
    private function readTrailers(): Headers
    {
        while (true) {
            $rest = $this->maxTrailersLength - $this->trailersLength;
            // The check only satisfies the type of readLine(): no line fits into a limit of 0 bytes anyway.
            $line = /* @infection-ignore GreaterThan */ $rest > 0 ? $this->readLine($rest) : null;

            if ($line === null) {
                throw new MalformedMessage(
                    message: \sprintf(
                        'The trailers of a chunked body must be at most %d bytes.',
                        $this->maxTrailersLength,
                    ),
                    status: Status::REQUEST_HEADER_FIELDS_TOO_LARGE,
                );
            }

            if ($line === '') {
                break;
            }

            // Every line is saved in a property as it is read, so a cancelled read continues with the next line.
            Fields::checkCount(\count($this->trailerLines) + 1, $this->maxTrailers);
            $this->trailerLines[] = $line;
            $this->trailersLength += \strlen($line) + 2;
        }

        if ($this->trailerLines === []) {
            return EMPTY_HEADERS;
        }

        $fields = Fields::lines($this->trailerLines, $this->maxTrailers);

        foreach (Fields::NOT_IN_TRAILERS as $name) {
            if (isset($fields[$name])) {
                throw new MalformedMessage(\sprintf('The field "%s" must not be in trailers.', $name));
            }
        }

        return new Headers($fields);
    }

    /**
     * @param positive-int $maxLength the limit for the line together with its CRLF
     * @return ?string null if the line is longer than the limit
     * @throws ReadFailed
     */
    private function readLine(int $maxLength): ?string
    {
        try {
            $this->buffer->fillUntil($this->reader, "\r\n", $maxLength);
        } catch (UnexpectedEof $eof) {
            throw self::cutShort($eof);
        } catch (\OverflowException) {
            return null;
        }

        return $this->buffer->readUntil("\r\n");
    }
}
