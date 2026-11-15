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
     * Reads exactly $max bytes, an empty string for 0.
     *
     * @param non-negative-int $max
     * @return ($max is positive-int ? non-empty-string : string)
     * @throws UnexpectedEof
     */
    public function read(int $max): string
    {
        $o = $this->offset;

        if (\strlen($this->bytes) - $o < $max) {
            throw $this->eof($max);
        }

        $this->offset = $o + $max;

        return substr($this->bytes, $o, $max);
    }

    /**
     * @param non-negative-int $n
     * @throws UnexpectedEof
     */
    public function skip(int $n): void
    {
        if (\strlen($this->bytes) - $this->offset < $n) {
            throw $this->eof($n);
        }

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
        if (\strlen($this->bytes) - $this->offset < $skip + $n) {
            throw $this->eof($skip + $n);
        }

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
        $o = $this->offset;

        if (\strlen($this->bytes) - $o < $n) {
            throw $this->eof($n);
        }

        // clone skips the constructor
        $slice = clone $this;
        $slice->bytes = substr($this->bytes, $o, $n);
        $slice->offset = 0;
        $this->offset = $o + $n;

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
        $o = $this->offset;
        $b = \ord(($this->bytes[$o] ?? throw $this->eof(1))[0]);
        $this->offset = $o + 1;

        // sign extension: 0x80..0xFF become -128..-1, faster than a comparison
        return ($b ^ 0x80) - 0x80;
    }

    /**
     * @return int<0, 255>
     * @throws UnexpectedEof
     */
    public function readUint8(): int
    {
        $o = $this->offset;
        $b = \ord(($this->bytes[$o] ?? throw $this->eof(1))[0]);
        $this->offset = $o + 1;

        return $b;
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt16(): int
    {
        $o = $this->offset;
        // the bounds check is folded into the fetch of the last byte
        $b = \ord(($this->bytes[$o + 1] ?? throw $this->eof(2))[0]);
        $this->offset = $o + 2;

        // sign extension, as in readInt8()
        return (($this->big
            ? \ord($this->bytes[$o]) << 8 | $b
            : $b << 8 | \ord($this->bytes[$o])) ^ 0x8000) - 0x8000;
    }

    /**
     * @return int<0, 65535>
     * @throws UnexpectedEof
     */
    public function readUint16(): int
    {
        $o = $this->offset;
        // the bounds check is folded into the fetch of the last byte
        $b = \ord(($this->bytes[$o + 1] ?? throw $this->eof(2))[0]);
        $this->offset = $o + 2;

        return $this->big
            ? \ord($this->bytes[$o]) << 8 | $b
            : $b << 8 | \ord($this->bytes[$o]);
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt32(): int
    {
        $o = $this->offset;

        if (\strlen($this->bytes) - $o < 4) {
            throw $this->eof(4);
        }

        $this->offset = $o + 4;

        /** @var array{1: non-negative-int} $v */
        $v = unpack($this->u32, $this->bytes, $o);

        // sign extension, as in readInt8()
        return ($v[1] ^ 0x80_00_00_00) - 0x80_00_00_00;
    }

    /**
     * @return int<0, 4294967295>
     * @throws UnexpectedEof
     */
    public function readUint32(): int
    {
        $o = $this->offset;

        if (\strlen($this->bytes) - $o < 4) {
            throw $this->eof(4);
        }

        $this->offset = $o + 4;

        /** @var array{1: int<0, 4294967295>} $v */
        $v = unpack($this->u32, $this->bytes, $o);

        return $v[1];
    }

    /**
     * @throws UnexpectedEof
     */
    public function readInt64(): int
    {
        $o = $this->offset;

        if (\strlen($this->bytes) - $o < 8) {
            throw $this->eof(8);
        }

        $this->offset = $o + 8;
        /** @var array{1: int} $v */
        $v = unpack($this->u64, $this->bytes, $o);

        return $v[1];
    }

    /**
     * @return non-negative-int
     * @throws UnexpectedEof
     * @throws \OverflowException if the value exceeds PHP_INT_MAX
     */
    public function readUint64(): int
    {
        $o = $this->offset;

        if (\strlen($this->bytes) - $o < 8) {
            throw $this->eof(8);
        }

        /** @var array{1: int} $v */
        $v = unpack($this->u64, $this->bytes, $o);

        if ($v[1] < 0) {
            throw new \OverflowException('Uint64 value exceeds PHP_INT_MAX.');
        }

        $this->offset = $o + 8;

        return $v[1];
    }

    /**
     * @throws UnexpectedEof
     */
    public function readFloat(): float
    {
        $o = $this->offset;

        if (\strlen($this->bytes) - $o < 4) {
            throw $this->eof(4);
        }

        $this->offset = $o + 4;

        /** @var array{1: float} $v */
        $v = unpack($this->f32, $this->bytes, $o);

        return $v[1];
    }

    /**
     * @throws UnexpectedEof
     */
    public function readDouble(): float
    {
        $o = $this->offset;

        if (\strlen($this->bytes) - $o < 8) {
            throw $this->eof(8);
        }

        $this->offset = $o + 8;

        /** @var array{1: float} $v */
        $v = unpack($this->f64, $this->bytes, $o);

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
        $b = \ord(($this->bytes[$o] ?? throw new UnexpectedEof('Unexpected end of varint.'))[0]);

        // a single byte is the most common case for tags and lengths
        if ($b < 0x80) {
            $this->offset = $o + 1;

            return $b;
        }

        $v = $b & 0x7F;

        for ($shift = 7; $shift < 64; $shift += 7) {
            $b = \ord(($this->bytes[++$o] ?? throw new UnexpectedEof('Unexpected end of varint.'))[0]);
            $v |= ($b & 0x7F) << $shift;

            if ($b < 0x80) {
                // the 10th byte may only hold bit 63
                if ($shift === 63 && $b > 1) {
                    break;
                }

                $this->offset = $o + 1;

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

        // 0, 1, 2, 3, ... decode to 0, -1, 1, -2, ...: branchless, faster than a comparison
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
        \assert($v >= -0x8000 && $v <= 0x7FFF, \sprintf('Expected an int16 value, got %d.', $v));
        // chr() is faster than pack() for 2 bytes
        $this->bytes .= $this->big
            ? \chr($v >> 8 & 0xFF) . \chr($v & 0xFF)
            : \chr($v & 0xFF) . \chr($v >> 8 & 0xFF);

        return $this;
    }

    public function writeUint16(int $v): self
    {
        \assert($v >= 0 && $v <= 0xFFFF, \sprintf('Expected a uint16 value, got %d.', $v));
        // chr() is faster than pack() for 2 bytes
        $this->bytes .= $this->big
            ? \chr($v >> 8 & 0xFF) . \chr($v & 0xFF)
            : \chr($v & 0xFF) . \chr($v >> 8 & 0xFF);

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
        if ($v >= 0) {
            if ($v < 0x80) {
                $this->bytes .= \chr($v);

                return $this;
            }

            if ($v < 0x4000) {
                $this->bytes .= \chr($v & 0x7F | 0x80) . \chr($v >> 7);

                return $this;
            }
        }

        $bytes = '';

        do {
            $bytes .= \chr($v & 0x7F | 0x80);
            // logical shift right: clear the sign bits spread by the arithmetic shift
            $v = $v >> 7 & 0x01_FF_FF_FF_FF_FF_FF_FF;
        } while ($v >= 0x80);

        $this->bytes .= $bytes . \chr($v);

        return $this;
    }

    /**
     * Writes a zigzag-encoded signed varint (protobuf sint32/sint64, Kafka varint/varlong).
     */
    public function writeVarint(int $v): self
    {
        // 0, -1, 1, -2, ... encode to 0, 1, 2, 3, ...: branchless, as in readVarint()
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
        \assert($mark + 1 <= \strlen($this->bytes), 'Invalid mark.');
        $this->bytes[$mark] = \chr($v);
    }

    /**
     * @param non-negative-int $mark
     */
    public function patchUint16(int $mark, int $v): void
    {
        \assert($v >= 0 && $v <= 0xFFFF, \sprintf('Expected a uint16 value, got %d.', $v));
        \assert($mark + 2 <= \strlen($this->bytes), 'Invalid mark.');

        if ($this->big) {
            $this->bytes[$mark] = \chr($v >> 8 & 0xFF);
            $this->bytes[$mark + 1] = \chr($v & 0xFF);
        } else {
            $this->bytes[$mark] = \chr($v & 0xFF);
            $this->bytes[$mark + 1] = \chr($v >> 8 & 0xFF);
        }
    }

    /**
     * @param non-negative-int $mark
     */
    public function patchUint32(int $mark, int $v): void
    {
        \assert($v >= 0 && $v <= 0xFF_FF_FF_FF, \sprintf('Expected a uint32 value, got %d.', $v));
        \assert($mark + 4 <= \strlen($this->bytes), 'Invalid mark.');

        // string offset assignment overwrites in place (no copy while the string is not shared)
        if ($this->big) {
            $this->bytes[$mark] = \chr($v >> 24 & 0xFF);
            $this->bytes[$mark + 1] = \chr($v >> 16 & 0xFF);
            $this->bytes[$mark + 2] = \chr($v >> 8 & 0xFF);
            $this->bytes[$mark + 3] = \chr($v & 0xFF);
        } else {
            $this->bytes[$mark] = \chr($v & 0xFF);
            $this->bytes[$mark + 1] = \chr($v >> 8 & 0xFF);
            $this->bytes[$mark + 2] = \chr($v >> 16 & 0xFF);
            $this->bytes[$mark + 3] = \chr($v >> 24 & 0xFF);
        }
    }

    /**
     * @param non-negative-int $mark
     * @param non-negative-int $v
     */
    public function patchUint64(int $mark, int $v): void
    {
        \assert($mark + 8 <= \strlen($this->bytes), 'Invalid mark.');
        $bytes = pack($this->u64, $v);

        for ($i = 0; $i < 8; ++$i) {
            $this->bytes[$mark + $i] = $bytes[$i];
        }
    }

    // ---------------------------------------------------------------- I/O

    /**
     * Reads from $reader until at least $n unread bytes are buffered.
     *
     * @throws UnexpectedEof
     */
    public function fill(Reader $reader, int $n): void
    {
        $missing = $n - \strlen($this->bytes) + $this->offset;

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
     * @param non-empty-string $delimiter
     * @param positive-int $maxLength max number of unread bytes to buffer while searching
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
     */
    private function eof(int $n): UnexpectedEof
    {
        return new UnexpectedEof(\sprintf(
            'Expected %d bytes, but only %d available.',
            $n,
            \strlen($this->bytes) - $this->offset,
        ));
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
}
