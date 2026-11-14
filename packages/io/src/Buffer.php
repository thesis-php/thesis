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

    private readonly bool $big;

    private readonly string $u16;

    private readonly string $u32;

    private readonly string $u64;

    private readonly string $f32;

    private readonly string $f64;

    /**
     * @param positive-int $chunkSize max number of bytes requested from a {@see Reader} at once in {@see fill()}
     */
    public function __construct(
        public readonly Order $order = Order::Network,
        private string $bytes = '',
        private readonly int $chunkSize = 65_536,
    ) {
        $this->big = $order === Order::Big;
        [$this->u16, $this->u32, $this->u64, $this->f32, $this->f64] = $this->big
            ? ['n', 'N', 'J', 'G', 'E']
            : ['v', 'V', 'P', 'g', 'e'];
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
     * Reads exactly $max bytes, an empty string for 0.
     *
     * @param non-negative-int $max
     * @return ($max is positive-int ? non-empty-string : string)
     * @throws UnexpectedEof
     */
    public function read(int $max): string
    {
        $this->ensure($max);
        $bytes = substr($this->bytes, $this->offset, $max);
        $this->offset += $max;

        return $bytes;
    }

    /**
     * @param non-negative-int $n
     * @throws UnexpectedEof
     */
    public function skip(int $n): void
    {
        $this->ensure($n);
        $this->offset += $n;
    }

    /**
     * Returns all unread bytes and clears the buffer.
     */
    public function drain(): string
    {
        $bytes = $this->offset === 0 ? $this->bytes : substr($this->bytes, $this->offset);
        $this->bytes = '';
        $this->offset = 0;

        return $bytes;
    }

    /**
     * Returns $n bytes after skipping $skip bytes without advancing the cursor.
     *
     * @param non-negative-int $n
     * @param non-negative-int $skip
     * @throws UnexpectedEof
     */
    public function peek(int $n, int $skip = 0): string
    {
        $this->ensure($skip + $n);

        return substr($this->bytes, $this->offset + $skip, $n);
    }

    /**
     * Reads $n bytes into a new buffer of the same byte order,
     * e.g. to decode a length-delimited nested message in isolation.
     *
     * @param non-negative-int $n
     * @throws UnexpectedEof
     */
    public function slice(int $n): self
    {
        $this->ensure($n);
        $slice = new self($this->order, substr($this->bytes, $this->offset, $n), $this->chunkSize);
        $this->offset += $n;

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
        $this->ensure(1);

        return (\ord($this->bytes[$this->offset++]) ^ 0x80) - 0x80;
    }

    /**
     * @return non-negative-int
     * @throws UnexpectedEof
     */
    public function readUint8(): int
    {
        $this->ensure(1);

        return \ord($this->bytes[$this->offset++]);
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt16(): int
    {
        $this->ensure(2);
        $o = $this->offset;
        $this->offset += 2;

        return (($this->big
            ? \ord($this->bytes[$o]) << 8 | \ord($this->bytes[$o + 1])
            : \ord($this->bytes[$o + 1]) << 8 | \ord($this->bytes[$o])) ^ 0x80_00) - 0x80_00;
    }

    /**
     * @return non-negative-int
     * @throws UnexpectedEof
     */
    public function readUint16(): int
    {
        $this->ensure(2);
        $o = $this->offset;
        $this->offset += 2;

        // ord() is faster than unpack() for 2 bytes
        return $this->big
            ? \ord($this->bytes[$o]) << 8 | \ord($this->bytes[$o + 1])
            : \ord($this->bytes[$o + 1]) << 8 | \ord($this->bytes[$o]);
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt32(): int
    {
        $this->ensure(4);
        /** @var array{1: non-negative-int} $v */
        $v = unpack($this->u32, $this->bytes, $this->offset);
        $this->offset += 4;

        return ($v[1] ^ 0x80_00_00_00) - 0x80_00_00_00;
    }

    /**
     * @return non-negative-int
     * @throws UnexpectedEof
     */
    public function readUint32(): int
    {
        $this->ensure(4);
        /** @var array{1: non-negative-int} $v */
        $v = unpack($this->u32, $this->bytes, $this->offset);
        $this->offset += 4;

        return $v[1];
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt64(): int
    {
        $this->ensure(8);
        /** @var array{1: int} $v */
        $v = unpack($this->u64, $this->bytes, $this->offset);
        $this->offset += 8;

        return $v[1];
    }

    /**
     * @return non-negative-int
     * @throws UnexpectedEof
     * @throws \OverflowException if the value exceeds PHP_INT_MAX
     */
    public function readUint64(): int
    {
        $this->ensure(8);
        /** @var array{1: int} $v */
        $v = unpack($this->u64, $this->bytes, $this->offset);

        if ($v[1] < 0) {
            throw new \OverflowException('Uint64 value exceeds PHP_INT_MAX.');
        }

        $this->offset += 8;

        return $v[1];
    }

    /**
     * @throws UnexpectedEof
     */
    public function readFloat(): float
    {
        $this->ensure(4);
        /** @var array{1: float} $v */
        $v = unpack($this->f32, $this->bytes, $this->offset);
        $this->offset += 4;

        return $v[1];
    }

    /**
     * @throws UnexpectedEof
     */
    public function readDouble(): float
    {
        $this->ensure(8);
        /** @var array{1: float} $v */
        $v = unpack($this->f64, $this->bytes, $this->offset);
        $this->offset += 8;

        return $v[1];
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
        $o = $this->offset;
        $length = \strlen($this->bytes);

        // fast path: a single byte, which is the most common case for tags and lengths
        if ($o < $length && ($b = \ord($this->bytes[$o])) < 0x80) {
            ++$this->offset;

            return $b;
        }

        $v = 0;

        for ($i = 0, $shift = 0; $i < 10; ++$i, $shift += 7) {
            if ($o + $i >= $length) {
                throw new UnexpectedEof('Unexpected end of varint.');
            }

            $b = \ord($this->bytes[$o + $i]);
            $v |= ($b & 0x7F) << $shift;

            if ($b < 0x80) {
                if ($i === 9 && $b > 1) {
                    break;
                }

                $this->offset = $o + $i + 1;

                return $v;
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
        $u = $this->readUvarint();

        return ($u >> 1 & PHP_INT_MAX) ^ -($u & 1);
    }

    // --------------------------------------------------------------- write

    public function write(string $bytes): self
    {
        $this->bytes .= $bytes;

        return $this;
    }

    public function writeInt8(int $v): self
    {
        \assert($v >= -0x80 && $v <= 0x7F, \sprintf('Expected an int8 value, got %d.', $v));
        $this->bytes .= \chr($v & 0xFF);

        return $this;
    }

    public function writeUint8(int $v): self
    {
        \assert($v >= 0 && $v <= 0xFF, \sprintf('Expected a uint8 value, got %d.', $v));
        $this->bytes .= \chr($v);

        return $this;
    }

    public function writeInt16(int $v): self
    {
        \assert($v >= -0x80_00 && $v <= 0x7F_FF, \sprintf('Expected an int16 value, got %d.', $v));
        $this->bytes .= pack($this->u16, $v);

        return $this;
    }

    public function writeUint16(int $v): self
    {
        \assert($v >= 0 && $v <= 0xFF_FF, \sprintf('Expected a uint16 value, got %d.', $v));
        $this->bytes .= pack($this->u16, $v);

        return $this;
    }

    public function writeInt32(int $v): self
    {
        \assert($v >= -0x80_00_00_00 && $v <= 0x7F_FF_FF_FF, \sprintf('Expected an int32 value, got %d.', $v));
        // pack() truncates negatives to two's complement
        $this->bytes .= pack($this->u32, $v);

        return $this;
    }

    public function writeUint32(int $v): self
    {
        \assert($v >= 0 && $v <= 0xFF_FF_FF_FF, \sprintf('Expected a uint32 value, got %d.', $v));
        $this->bytes .= pack($this->u32, $v);

        return $this;
    }

    public function writeInt64(int $v): self
    {
        $this->bytes .= pack($this->u64, $v);

        return $this;
    }

    /**
     * @param non-negative-int $v
     */
    public function writeUint64(int $v): self
    {
        $this->bytes .= pack($this->u64, $v);

        return $this;
    }

    public function writeFloat(float $v): self
    {
        $this->bytes .= pack($this->f32, $v);

        return $this;
    }

    public function writeDouble(float $v): self
    {
        $this->bytes .= pack($this->f64, $v);

        return $this;
    }

    /**
     * Writes an unsigned LEB128 varint. A negative $v is written as its 64-bit two's complement pattern (10 bytes),
     * which is how protobuf encodes negative int32/int64.
     */
    public function writeUvarint(int $v): self
    {
        if ($v >= 0 && $v < 0x80) {
            $this->bytes .= \chr($v);

            return $this;
        }

        $bytes = '';

        do {
            $b = $v & 0x7F;
            // logical shift right: clear the sign bits spread by the arithmetic shift
            $v = $v >> 7 & 0x01_FF_FF_FF_FF_FF_FF_FF;
            $bytes .= \chr($v === 0 ? $b : $b | 0x80);
        } while ($v !== 0);

        $this->bytes .= $bytes;

        return $this;
    }

    /**
     * Writes a zigzag-encoded signed varint (protobuf sint32/sint64, Kafka varint/varlong).
     */
    public function writeVarint(int $v): self
    {
        return $this->writeUvarint($v << 1 ^ $v >> 63);
    }

    // ------------------------------------------------------ reserve/patch

    /**
     * Appends $n zero bytes and returns a mark pointing at them, to be patched later,
     * e.g. with a length that is unknown until the payload is written.
     *
     *     $mark = $buffer->reserve(4);
     *     // write payload...
     *     $buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);
     *
     * Marks are invalidated by {@see fill()}, {@see flush()} and {@see drain()}.
     *
     * @param non-negative-int $n
     * @return non-negative-int
     */
    public function reserve(int $n): int
    {
        $mark = \strlen($this->bytes);
        $this->bytes .= str_repeat("\0", $n);

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
    public function patchUint8(int $mark, int $v): void
    {
        \assert($v >= 0 && $v <= 0xFF, \sprintf('Expected a uint8 value, got %d.', $v));
        $this->patch($mark, \chr($v));
    }

    /**
     * @param non-negative-int $mark
     */
    public function patchUint16(int $mark, int $v): void
    {
        \assert($v >= 0 && $v <= 0xFF_FF, \sprintf('Expected a uint16 value, got %d.', $v));
        $this->patch($mark, pack($this->u16, $v));
    }

    /**
     * @param non-negative-int $mark
     */
    public function patchUint32(int $mark, int $v): void
    {
        \assert($v >= 0 && $v <= 0xFF_FF_FF_FF, \sprintf('Expected a uint32 value, got %d.', $v));
        $this->patch($mark, pack($this->u32, $v));
    }

    /**
     * @param non-negative-int $mark
     * @param non-negative-int $v
     */
    public function patchUint64(int $mark, int $v): void
    {
        $this->patch($mark, pack($this->u64, $v));
    }

    // ---------------------------------------------------------------- I/O

    /**
     * Reads from $reader until at least $n unread bytes are buffered.
     *
     * @throws UnexpectedEof
     */
    public function fill(Reader $reader, int $n): void
    {
        $missing = $n - $this->count();

        if ($missing <= 0) {
            return;
        }

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
     * @param positive-int $maxLength max number of unread bytes to buffer while searching
     * @param non-empty-string $delimiter
     * @throws UnexpectedEof
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
            $scanned = max(0, $available - \strlen($delimiter) + 1);
            $this->compact();
            /** @var positive-int $missing $available < $maxLength here */
            $missing = $maxLength - $available;
            $this->bytes .= $reader->read(min($this->chunkSize, $missing));
        }

        throw new \OverflowException(\sprintf('Delimiter not found within %d bytes.', $maxLength));
    }

    /**
     * Writes all unread bytes to $writer and clears the buffer.
     *
     * @throws WriteFailed
     */
    public function flush(Writer $writer): void
    {
        $bytes = $this->drain();

        if ($bytes !== '') {
            $writer->write($bytes);
        }
    }

    // ------------------------------------------------------------ private

    /**
     * @param non-negative-int $n
     * @throws UnexpectedEof
     */
    private function ensure(int $n): void
    {
        if (\strlen($this->bytes) - $this->offset < $n) {
            throw new UnexpectedEof(\sprintf('Expected %d bytes, but only %d available.', $n, \strlen($this->bytes) - $this->offset));
        }
    }

    private function compact(): void
    {
        if ($this->offset === \strlen($this->bytes)) {
            $this->bytes = '';
            $this->offset = 0;
        } elseif ($this->offset >= self::COMPACT_THRESHOLD) {
            $this->bytes = substr($this->bytes, $this->offset);
            $this->offset = 0;
        }
    }

    /**
     * Overwrites bytes in place: string offset assignment does not copy the buffer.
     *
     * @param non-negative-int $mark
     */
    private function patch(int $mark, string $bytes): void
    {
        $n = \strlen($bytes);
        \assert($mark + $n <= \strlen($this->bytes), 'Invalid mark.');

        for ($i = 0; $i < $n; ++$i) {
            $this->bytes[$mark + $i] = $bytes[$i];
        }
    }
}
