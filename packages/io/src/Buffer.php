<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * In-memory byte buffer with a read cursor and typed binary encoding.
 *
 * Reads advance the cursor instead of cutting the string, so every read is O(1).
 * Writes append to the end. Consumed bytes are discarded lazily in {@see fill()}.
 *
 * Range checks on write are done with {@see assert()}, so they cost nothing
 * when `zend.assertions = -1`.
 *
 * @api
 */
final class Buffer implements \Countable
{
    /**
     * Consumed bytes are discarded on {@see fill()} once their amount reaches this value.
     */
    private const int COMPACT_THRESHOLD = 65_536;

    private int $offset = 0;

    // Not readonly: a readonly property cannot have a default value, and assigning all of them
    // in the constructor makes Kafka/protobuf decoding 5-9% slower. Big endian is the default,
    // so the constructor assigns them only for little endian.

    private bool $big = true;

    private string $u32 = 'N';

    private string $u64 = 'J';

    private string $f32 = 'G';

    private string $f64 = 'E';

    /**
     * @param positive-int $chunkSize max number of bytes requested from a {@see Reader} at once in {@see fill()}
     */
    public function __construct(
        public readonly Order $order = Order::Network,
        private string $bytes = '',
        private readonly int $chunkSize = 65_536,
    ) {
        if ($order === Order::Little) {
            $this->big = false;
            $this->u32 = 'V';
            $this->u64 = 'P';
            $this->f32 = 'g';
            $this->f64 = 'e';
        }
    }

    /**
     * Number of unread bytes.
     *
     * @return non-negative-int
     */
    public function count(): int
    {
        /** @var non-negative-int Countable::count() is declared so */
        return \strlen($this->bytes) - $this->offset;
    }

    // ---------------------------------------------------------------- read

    /**
     * Reads exactly $length bytes, an empty string for 0.
     *
     * @param non-negative-int $length
     * @return ($length is positive-int ? non-empty-string : string)
     * @throws UnexpectedEof
     */
    public function read(int $length): string
    {
        $offset = $this->offset;

        if (\strlen($this->bytes) - $offset < $length) {
            throw $this->eof($length);
        }

        $this->offset = $offset + $length;

        return substr($this->bytes, $offset, $length);
    }

    /**
     * @param non-negative-int $length
     * @throws UnexpectedEof
     */
    public function skip(int $length): void
    {
        if (\strlen($this->bytes) - $this->offset < $length) {
            throw $this->eof($length);
        }

        $this->offset += $length;
    }

    /**
     * Returns all unread bytes and clears the buffer.
     */
    public function drain(): string
    {
        // substr() of the whole string returns it without a copy
        $bytes = substr($this->bytes, $this->offset);
        $this->bytes = '';
        $this->offset = 0;

        return $bytes;
    }

    /**
     * Returns $length bytes starting $offset bytes after the cursor, without advancing it.
     *
     * @param non-negative-int $length
     * @param non-negative-int $offset
     * @throws UnexpectedEof
     */
    public function peek(int $length, int $offset = 0): string
    {
        if (\strlen($this->bytes) - $this->offset < $offset + $length) {
            throw $this->eof($offset + $length);
        }

        return substr($this->bytes, $this->offset + $offset, $length);
    }

    /**
     * Reads $length bytes into a new buffer of the same byte order,
     * e.g. to decode a length-delimited nested message in isolation.
     *
     * @param non-negative-int $length
     * @throws UnexpectedEof
     */
    public function slice(int $length): self
    {
        $offset = $this->offset;

        if (\strlen($this->bytes) - $offset < $length) {
            throw $this->eof($length);
        }

        // clone skips the constructor
        $slice = clone $this;
        $slice->bytes = substr($this->bytes, $offset, $length);
        $slice->offset = 0;
        $this->offset = $offset + $length;

        return $slice;
    }

    /**
     * Reads bytes up to the delimiter, consumes the delimiter and returns the bytes before it.
     * Returns null and consumes nothing if the delimiter is not buffered.
     *
     * @param non-empty-string $delimiter
     */
    public function readUntil(string $delimiter): ?string
    {
        $pos = strpos($this->bytes, $delimiter, $this->offset);

        if ($pos === false) {
            return null;
        }

        $bytes = substr($this->bytes, $this->offset, $pos - $this->offset);
        $this->offset = $pos + \strlen($delimiter);

        return $bytes;
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt8(): int
    {
        $offset = $this->offset;
        $byte = \ord(($this->bytes[$offset] ?? throw $this->eof(1))[0]);
        $this->offset = $offset + 1;

        // sign extension: 0x80..0xFF become -128..-1, faster than a comparison
        return ($byte ^ 0x80) - 0x80;
    }

    /**
     * @return int<0, 255>
     * @throws UnexpectedEof
     */
    public function readUint8(): int
    {
        $offset = $this->offset;
        $byte = \ord(($this->bytes[$offset] ?? throw $this->eof(1))[0]);
        $this->offset = $offset + 1;

        return $byte;
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt16(): int
    {
        $offset = $this->offset;
        // the bounds check is folded into the fetch of the last byte
        $byte = \ord(($this->bytes[$offset + 1] ?? throw $this->eof(2))[0]);
        $this->offset = $offset + 2;

        // sign extension, as in readInt8()
        return (($this->big
            ? \ord($this->bytes[$offset]) << 8 | $byte
            : $byte << 8 | \ord($this->bytes[$offset])) ^ 0x8000) - 0x8000;
    }

    /**
     * @return int<0, 65535>
     * @throws UnexpectedEof
     */
    public function readUint16(): int
    {
        $offset = $this->offset;
        // the bounds check is folded into the fetch of the last byte
        $byte = \ord(($this->bytes[$offset + 1] ?? throw $this->eof(2))[0]);
        $this->offset = $offset + 2;

        return $this->big
            ? \ord($this->bytes[$offset]) << 8 | $byte
            : $byte << 8 | \ord($this->bytes[$offset]);
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt32(): int
    {
        $offset = $this->offset;

        if (\strlen($this->bytes) - $offset < 4) {
            throw $this->eof(4);
        }

        $this->offset = $offset + 4;

        /** @var array{1: non-negative-int} $value */
        $value = unpack($this->u32, $this->bytes, $offset);

        // sign extension, as in readInt8()
        return ($value[1] ^ 0x80_00_00_00) - 0x80_00_00_00;
    }

    /**
     * @return int<0, 4294967295>
     * @throws UnexpectedEof
     */
    public function readUint32(): int
    {
        $offset = $this->offset;

        if (\strlen($this->bytes) - $offset < 4) {
            throw $this->eof(4);
        }

        $this->offset = $offset + 4;

        /** @var array{1: int<0, 4294967295>} $value */
        $value = unpack($this->u32, $this->bytes, $offset);

        return $value[1];
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt64(): int
    {
        $offset = $this->offset;

        if (\strlen($this->bytes) - $offset < 8) {
            throw $this->eof(8);
        }

        $this->offset = $offset + 8;
        /** @var array{1: int} $value */
        $value = unpack($this->u64, $this->bytes, $offset);

        return $value[1];
    }

    /**
     * @return non-negative-int
     * @throws UnexpectedEof
     * @throws \OverflowException if the value exceeds PHP_INT_MAX
     */
    public function readUint64(): int
    {
        $offset = $this->offset;

        if (\strlen($this->bytes) - $offset < 8) {
            throw $this->eof(8);
        }

        /** @var array{1: int} $value */
        $value = unpack($this->u64, $this->bytes, $offset);

        if ($value[1] < 0) {
            throw new \OverflowException('Uint64 value exceeds PHP_INT_MAX.');
        }

        $this->offset = $offset + 8;

        return $value[1];
    }

    /**
     * @throws UnexpectedEof
     */
    public function readFloat(): float
    {
        $offset = $this->offset;

        if (\strlen($this->bytes) - $offset < 4) {
            throw $this->eof(4);
        }

        $this->offset = $offset + 4;

        /** @var array{1: float} $value */
        $value = unpack($this->f32, $this->bytes, $offset);

        return $value[1];
    }

    /**
     * @throws UnexpectedEof
     */
    public function readDouble(): float
    {
        $offset = $this->offset;

        if (\strlen($this->bytes) - $offset < 8) {
            throw $this->eof(8);
        }

        $this->offset = $offset + 8;

        /** @var array{1: float} $value */
        $value = unpack($this->f64, $this->bytes, $offset);

        return $value[1];
    }

    /**
     * Reads an unsigned LEB128 varint (protobuf varint, Kafka unsigned varint) of up to 10 bytes.
     * Values above PHP_INT_MAX are returned as their 64-bit two's complement pattern (negative int).
     *
     * @throws UnexpectedEof
     * @throws \OverflowException if the varint does not fit into 64 bits
     */
    public function readUvarint(): int
    {
        $offset = $this->offset;
        $byte = \ord(($this->bytes[$offset] ?? throw new UnexpectedEof('Unexpected end of varint.'))[0]);

        // a single byte is the most common case for tags and lengths
        if ($byte < 0x80) {
            $this->offset = $offset + 1;

            return $byte;
        }

        $value = $byte & 0x7F;

        // the shift grows by 7 and never equals 64, so < 64 and <= 64 stop at the same step
        for ($shift = 7; /* @infection-ignore LessThan */ $shift < 64; $shift += 7) {
            $byte = \ord(($this->bytes[++$offset] ?? throw new UnexpectedEof('Unexpected end of varint.'))[0]);
            $value |= ($byte & 0x7F) << $shift;

            if ($byte < 0x80) {
                // the 10th byte may only hold bit 63
                if ($shift === 63 && $byte > 1) {
                    throw new \OverflowException('Varint overflows 64 bits.');
                }

                $this->offset = $offset + 1;

                return $value;
            }
        }

        throw new \OverflowException('Varint overflows 64 bits.');
    }

    /**
     * Reads a zigzag-encoded signed varint (protobuf sint32/sint64, Kafka varint/varlong).
     *
     * @throws UnexpectedEof
     * @throws \OverflowException if the varint does not fit into 64 bits
     */
    public function readVarint(): int
    {
        $unsigned = $this->readUvarint();

        // 0, 1, 2, 3, ... decode to 0, -1, 1, -2, ...: branchless, faster than a comparison
        return ($unsigned >> 1 & PHP_INT_MAX) ^ -($unsigned & 1);
    }

    // --------------------------------------------------------------- write

    public function write(string $bytes): self
    {
        $this->bytes .= $bytes;

        return $this;
    }

    public function writeInt8(int $value): self
    {
        \assert($value >= -0x80 && $value <= 0x7F, \sprintf('Expected an int8 value, got %d.', $value));
        $this->bytes .= \chr($value & 0xFF);

        return $this;
    }

    public function writeUint8(int $value): self
    {
        \assert($value >= 0 && $value <= 0xFF, \sprintf('Expected a uint8 value, got %d.', $value));
        $this->bytes .= \chr($value);

        return $this;
    }

    public function writeInt16(int $value): self
    {
        \assert($value >= -0x8000 && $value <= 0x7FFF, \sprintf('Expected an int16 value, got %d.', $value));
        // chr() is faster than pack() for 2 bytes
        $this->bytes .= $this->big
            ? \chr($value >> 8 & 0xFF) . \chr($value & 0xFF)
            : \chr($value & 0xFF) . \chr($value >> 8 & 0xFF);

        return $this;
    }

    public function writeUint16(int $value): self
    {
        \assert($value >= 0 && $value <= 0xFFFF, \sprintf('Expected a uint16 value, got %d.', $value));
        // chr() is faster than pack() for 2 bytes
        $this->bytes .= $this->big
            ? \chr($value >> 8 & 0xFF) . \chr($value & 0xFF)
            : \chr($value & 0xFF) . \chr($value >> 8 & 0xFF);

        return $this;
    }

    public function writeInt32(int $value): self
    {
        \assert(
            assertion: $value >= -0x80_00_00_00 && $value <= 0x7F_FF_FF_FF,
            description: \sprintf('Expected an int32 value, got %d.', $value),
        );
        // pack() truncates negatives to two's complement
        $this->bytes .= pack($this->u32, $value);

        return $this;
    }

    public function writeUint32(int $value): self
    {
        \assert($value >= 0 && $value <= 0xFF_FF_FF_FF, \sprintf('Expected a uint32 value, got %d.', $value));
        $this->bytes .= pack($this->u32, $value);

        return $this;
    }

    public function writeInt64(int $value): self
    {
        $this->bytes .= pack($this->u64, $value);

        return $this;
    }

    /**
     * @param non-negative-int $value
     */
    public function writeUint64(int $value): self
    {
        $this->bytes .= pack($this->u64, $value);

        return $this;
    }

    public function writeFloat(float $value): self
    {
        $this->bytes .= pack($this->f32, $value);

        return $this;
    }

    public function writeDouble(float $value): self
    {
        $this->bytes .= pack($this->f64, $value);

        return $this;
    }

    /**
     * Writes an unsigned LEB128 varint. A negative $value is written as its 64-bit two's complement pattern (10 bytes),
     * which is how protobuf encodes negative int32/int64.
     */
    public function writeUvarint(int $value): self
    {
        if ($value >= 0) {
            if ($value < 0x80) {
                $this->bytes .= \chr($value);

                return $this;
            }

            if ($value < 0x4000) {
                $this->bytes .= \chr($value & 0x7F | 0x80) . \chr($value >> 7);

                return $this;
            }
        }

        $bytes = '';

        do {
            $bytes .= \chr($value & 0x7F | 0x80);
            // logical shift right: clear the sign bits spread by the arithmetic shift
            $value = $value >> 7 & 0x01_FF_FF_FF_FF_FF_FF_FF;
        } while ($value >= 0x80);

        $this->bytes .= $bytes . \chr($value);

        return $this;
    }

    /**
     * Writes a zigzag-encoded signed varint (protobuf sint32/sint64, Kafka varint/varlong).
     */
    public function writeVarint(int $value): self
    {
        // 0, -1, 1, -2, ... encode to 0, 1, 2, 3, ...: branchless, as in readVarint()
        // PHP shifts right by 64 and more as by 63: the sign fills every bit.
        // @infection-ignore IncrementInteger
        return $this->writeUvarint($value << 1 ^ $value >> 63);
    }

    // ------------------------------------------------------ reserve/patch

    /**
     * Appends $length zero bytes and returns a mark pointing at them, to be patched later,
     * e.g. with a length that is unknown until the payload is written.
     *
     *     $mark = $buffer->reserve(4);
     *     // write payload...
     *     $buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);
     *
     * Marks are invalidated by {@see fill()}, {@see flush()} and {@see drain()}.
     *
     * @param non-negative-int $length
     * @return non-negative-int
     */
    public function reserve(int $length): int
    {
        $mark = \strlen($this->bytes);
        $this->bytes .= str_repeat("\0", $length);

        return $mark;
    }

    /**
     * Number of bytes written starting from the mark.
     *
     * @param non-negative-int $mark
     * @return non-negative-int
     */
    public function sizeSince(int $mark): int
    {
        \assert($mark <= \strlen($this->bytes), 'Invalid mark.');

        /** @var non-negative-int */
        return \strlen($this->bytes) - $mark;
    }

    /**
     * @param non-negative-int $mark
     */
    public function patchUint8(int $mark, int $value): void
    {
        \assert($value >= 0 && $value <= 0xFF, \sprintf('Expected a uint8 value, got %d.', $value));
        \assert($mark + 1 <= \strlen($this->bytes), 'Invalid mark.');
        $this->bytes[$mark] = \chr($value);
    }

    /**
     * @param non-negative-int $mark
     */
    public function patchUint16(int $mark, int $value): void
    {
        \assert($value >= 0 && $value <= 0xFFFF, \sprintf('Expected a uint16 value, got %d.', $value));
        \assert($mark + 2 <= \strlen($this->bytes), 'Invalid mark.');

        if ($this->big) {
            $this->bytes[$mark] = \chr($value >> 8 & 0xFF);
            $this->bytes[$mark + 1] = \chr($value & 0xFF);
        } else {
            $this->bytes[$mark] = \chr($value & 0xFF);
            $this->bytes[$mark + 1] = \chr($value >> 8 & 0xFF);
        }
    }

    /**
     * @param non-negative-int $mark
     */
    public function patchUint32(int $mark, int $value): void
    {
        \assert($value >= 0 && $value <= 0xFF_FF_FF_FF, \sprintf('Expected a uint32 value, got %d.', $value));
        \assert($mark + 4 <= \strlen($this->bytes), 'Invalid mark.');

        // string offset assignment overwrites in place (no copy while the string is not shared)
        if ($this->big) {
            $this->bytes[$mark] = \chr($value >> 24 & 0xFF);
            $this->bytes[$mark + 1] = \chr($value >> 16 & 0xFF);
            $this->bytes[$mark + 2] = \chr($value >> 8 & 0xFF);
            $this->bytes[$mark + 3] = \chr($value & 0xFF);
        } else {
            $this->bytes[$mark] = \chr($value & 0xFF);
            $this->bytes[$mark + 1] = \chr($value >> 8 & 0xFF);
            $this->bytes[$mark + 2] = \chr($value >> 16 & 0xFF);
            $this->bytes[$mark + 3] = \chr($value >> 24 & 0xFF);
        }
    }

    /**
     * @param non-negative-int $mark
     * @param non-negative-int $value
     */
    public function patchUint64(int $mark, int $value): void
    {
        \assert($mark + 8 <= \strlen($this->bytes), 'Invalid mark.');
        $bytes = pack($this->u64, $value);

        for ($i = 0; $i < 8; ++$i) {
            $this->bytes[$mark + $i] = $bytes[$i];
        }
    }

    // ---------------------------------------------------------------- I/O

    /**
     * Reads from $reader until at least $length unread bytes are buffered. If a read throws, the bytes read
     * before stay buffered: nothing is lost, as a read that throws has taken nothing.
     *
     * @throws UnexpectedEof
     * @throws IoException of the reader: {@see ReadFailed}, a closed stream
     */
    public function fill(Reader $reader, int $length): void
    {
        $missing = $length - \strlen($this->bytes) + $this->offset;

        if ($missing <= 0) {
            return;
        }

        // Compaction only frees the memory of the bytes read: the unread bytes are the same with it and without.
        // @infection-ignore MethodCallRemoval
        $this->compact();

        do {
            $bytes = $reader->read(max($missing, $this->chunkSize));
            $this->bytes .= $bytes;
            $missing -= \strlen($bytes);
        } while ($missing > 0);
    }

    /**
     * Reads from $reader until the delimiter is buffered, e.g. to read a line of a text protocol:
     *
     *     $buffer->fillUntil($reader, "\r\n");
     *     $line = $buffer->readUntil("\r\n");
     *
     * @param non-empty-string $delimiter
     * @param positive-int $maxLength max number of unread bytes to buffer while searching
     * @throws UnexpectedEof
     * @throws IoException of the reader: {@see ReadFailed}, a closed stream
     * @throws \OverflowException if the delimiter is not found within $maxLength bytes
     */
    public function fillUntil(Reader $reader, string $delimiter, int $maxLength = 65_536): void
    {
        // number of unread bytes already searched, so that they are not rescanned after each chunk
        $scanned = 0;

        while (true) {
            $pos = strpos($this->bytes, $delimiter, $this->offset + $scanned);

            if ($pos !== false) {
                if ($pos + \strlen($delimiter) - $this->offset > $maxLength) {
                    break;
                }

                return;
            }

            $available = $this->count();

            if ($available >= $maxLength) {
                break;
            }

            // keep a tail for a delimiter split between chunks
            // A longer tail only searches longer: the delimiter is found anyway.
            // @infection-ignore Plus, DecrementInteger
            $scanned = max(0, $available - \strlen($delimiter) + 1);
            // Compaction only frees the memory of the bytes read: the unread bytes are the same with it and without.
            // @infection-ignore MethodCallRemoval
            $this->compact();
            /** @var positive-int $missing $available < $maxLength here */
            $missing = $maxLength - $available;
            $this->bytes .= $reader->read(min($this->chunkSize, $missing));
        }

        throw new \OverflowException(\sprintf('Delimiter not found within %d bytes.', $maxLength));
    }

    /**
     * Writes all unread bytes to $writer and clears the buffer. If the write throws, the bytes are back
     * at the start, before those written meanwhile (by another fiber while it waited): a writer that wrote
     * nothing may be flushed again, and one that wrote a part is unusable anyway.
     *
     * @throws IoException of the writer
     */
    public function flush(Writer $writer): void
    {
        $bytes = $this->drain();

        if ($bytes === '') {
            return;
        }

        try {
            $writer->write($bytes);
        } catch (\Throwable $error) {
            $this->bytes = $bytes . substr($this->bytes, $this->offset);
            $this->offset = 0;

            throw $error;
        }
    }

    // ------------------------------------------------------------ private

    /**
     * @param non-negative-int $length
     */
    private function eof(int $length): UnexpectedEof
    {
        return new UnexpectedEof(\sprintf(
            'Expected %d bytes, but only %d available.',
            $length,
            \strlen($this->bytes) - $this->offset,
        ));
    }

    private function compact(): void
    {
        if ($this->offset === \strlen($this->bytes)) {
            $this->bytes = '';
            $this->offset = 0;
        } elseif (
            // the threshold only decides when the memory of the bytes read is freed
            // @infection-ignore GreaterThanOrEqualTo, GreaterThanOrEqualToNegotiation
            $this->offset >= self::COMPACT_THRESHOLD
        ) {
            $this->bytes = substr($this->bytes, $this->offset);
            $this->offset = 0;
        }
    }
}
