<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * A deliberately naive implementation of the {@see Buffer} contract, used as an oracle in {@see BufferFuzzTest}.
 *
 * Nothing here is optimized and the algorithms differ from {@see Buffer} where possible: consumed bytes are cut off
 * with substr(), integers are assembled byte by byte, varints are converted through strings of bits.
 */
final class ReferenceBuffer
{
    /**
     * Number of bytes cut off the beginning, so that marks are absolute positions.
     */
    private int $consumed = 0;

    public function __construct(
        public readonly Order $order,
        private string $bytes,
        private readonly int $chunkSize,
    ) {}

    public function count(): int
    {
        return \strlen($this->bytes);
    }

    public function read(int $n): string
    {
        return $this->take($n);
    }

    public function skip(int $n): void
    {
        $this->take($n);
    }

    public function drain(): string
    {
        return $this->take(\strlen($this->bytes));
    }

    public function peek(int $n, int $skip = 0): string
    {
        $this->need($skip + $n);

        return substr($this->bytes, $skip, $n);
    }

    public function slice(int $n): self
    {
        return new self($this->order, $this->take($n), $this->chunkSize);
    }

    public function readUntil(string $delimiter): ?string
    {
        $pos = strpos($this->bytes, $delimiter);

        if ($pos === false) {
            return null;
        }

        $bytes = $this->take($pos);
        $this->take(\strlen($delimiter));

        return $bytes;
    }

    public function readInt8(): int
    {
        return self::signed($this->readUnsigned(1), 1);
    }

    public function readUint8(): int
    {
        return $this->readUnsigned(1);
    }

    public function readInt16(): int
    {
        return self::signed($this->readUnsigned(2), 2);
    }

    public function readUint16(): int
    {
        return $this->readUnsigned(2);
    }

    public function readInt32(): int
    {
        return self::signed($this->readUnsigned(4), 4);
    }

    public function readUint32(): int
    {
        return $this->readUnsigned(4);
    }

    public function readInt64(): int
    {
        return $this->readUnsigned(8);
    }

    public function readUint64(): int
    {
        $this->need(8);
        $first = \ord($this->bytes[$this->order === Order::Big ? 0 : 7]);

        if ($first >= 0x80) {
            throw new \OverflowException('Uint64 value exceeds PHP_INT_MAX.');
        }

        return $this->readUnsigned(8);
    }

    public function readFloat(): float
    {
        $bytes = $this->ordered($this->take(4));
        /** @var array{1: float} $v */
        $v = unpack('G', $bytes);

        return $v[1];
    }

    public function readDouble(): float
    {
        $bytes = $this->ordered($this->take(8));
        /** @var array{1: float} $v */
        $v = unpack('E', $bytes);

        return $v[1];
    }

    public function readUvarint(): int
    {
        $bits = '';

        for ($i = 0; $i < 10; ++$i) {
            if ($i >= \strlen($this->bytes)) {
                throw new UnexpectedEof('Unexpected end of varint.');
            }

            $byte = \ord($this->bytes[$i]);
            $bits = \sprintf('%07b', $byte & 0x7F) . $bits;

            if ($byte < 0x80) {
                $bits = ltrim($bits, '0');

                if (\strlen($bits) > 64) {
                    break;
                }

                $this->take($i + 1);
                $bits = str_pad($bits, 64, '0', STR_PAD_LEFT);

                return (int) bindec(substr($bits, 0, 32)) << 32 | (int) bindec(substr($bits, 32));
            }
        }

        throw new \OverflowException('Varint overflows 64 bits.');
    }

    public function readVarint(): int
    {
        $u = $this->readUvarint();
        $half = ($u >> 1) & PHP_INT_MAX;

        return ($u & 1) === 0 ? $half : ~$half;
    }

    public function write(string $bytes): self
    {
        $this->bytes .= $bytes;

        return $this;
    }

    public function writeInt8(int $v): self
    {
        return $this->write($this->encode($v, 1));
    }

    public function writeUint8(int $v): self
    {
        return $this->write($this->encode($v, 1));
    }

    public function writeInt16(int $v): self
    {
        return $this->write($this->encode($v, 2));
    }

    public function writeUint16(int $v): self
    {
        return $this->write($this->encode($v, 2));
    }

    public function writeInt32(int $v): self
    {
        return $this->write($this->encode($v, 4));
    }

    public function writeUint32(int $v): self
    {
        return $this->write($this->encode($v, 4));
    }

    public function writeInt64(int $v): self
    {
        return $this->write($this->encode($v, 8));
    }

    public function writeUint64(int $v): self
    {
        return $this->write($this->encode($v, 8));
    }

    public function writeFloat(float $v): self
    {
        return $this->write($this->ordered(pack('G', $v)));
    }

    public function writeDouble(float $v): self
    {
        return $this->write($this->ordered(pack('E', $v)));
    }

    public function writeUvarint(int $v): self
    {
        // %b prints the 64-bit two's complement pattern of a negative int
        $bits = ltrim(\sprintf('%064b', $v), '0');
        $groups = str_split(str_pad($bits, (int) ceil(max(1, \strlen($bits)) / 7) * 7, '0', STR_PAD_LEFT), 7);
        $groups = array_reverse($groups);
        $last = \count($groups) - 1;

        foreach ($groups as $i => $group) {
            $this->bytes .= \chr(((int) bindec($group) | ($i === $last ? 0 : 0x80)) & 0xFF);
        }

        return $this;
    }

    public function writeVarint(int $v): self
    {
        return $this->writeUvarint($v >= 0 ? $v << 1 : (~$v << 1) | 1);
    }

    public function reserve(int $n): int
    {
        $mark = $this->consumed + \strlen($this->bytes);
        $this->bytes .= str_repeat("\0", $n);

        return $mark;
    }

    public function sizeSince(int $mark): int
    {
        return $this->consumed + \strlen($this->bytes) - $mark;
    }

    public function patchUint8(int $mark, int $v): void
    {
        $this->patch($mark, $this->encode($v, 1));
    }

    public function patchUint16(int $mark, int $v): void
    {
        $this->patch($mark, $this->encode($v, 2));
    }

    public function patchUint32(int $mark, int $v): void
    {
        $this->patch($mark, $this->encode($v, 4));
    }

    public function patchUint64(int $mark, int $v): void
    {
        $this->patch($mark, $this->encode($v, 8));
    }

    /**
     * @param positive-int $n
     */
    public function fill(Reader $reader, int $n): void
    {
        while (\strlen($this->bytes) < $n) {
            /** @var positive-int $max */
            $max = max($n - \strlen($this->bytes), $this->chunkSize);
            $this->bytes .= $reader->read($max);
        }
    }

    /**
     * @param non-empty-string $delimiter
     * @param positive-int $maxLength
     */
    public function fillUntil(Reader $reader, string $delimiter, int $maxLength): void
    {
        while (true) {
            $pos = strpos($this->bytes, $delimiter);

            if ($pos !== false) {
                if ($pos + \strlen($delimiter) > $maxLength) {
                    break;
                }

                return;
            }

            if (\strlen($this->bytes) >= $maxLength) {
                break;
            }

            /** @var positive-int $max */
            $max = min($this->chunkSize, $maxLength - \strlen($this->bytes));
            $this->bytes .= $reader->read($max);
        }

        throw new \OverflowException(\sprintf('Delimiter not found within %d bytes.', $maxLength));
    }

    public function flush(Writer $writer): void
    {
        $bytes = $this->drain();

        if ($bytes !== '') {
            $writer->write($bytes);
        }
    }

    private function need(int $n): void
    {
        if (\strlen($this->bytes) < $n) {
            throw new UnexpectedEof(\sprintf('Expected %d bytes, but only %d available.', $n, \strlen($this->bytes)));
        }
    }

    private function take(int $n): string
    {
        $this->need($n);
        $bytes = substr($this->bytes, 0, $n);
        $this->bytes = substr($this->bytes, $n);
        $this->consumed += $n;

        return $bytes;
    }

    private function readUnsigned(int $size): int
    {
        $v = 0;

        foreach (str_split($this->ordered($this->take($size))) as $byte) {
            $v = $v << 8 | \ord($byte[0]);
        }

        return $v;
    }

    private static function signed(int $v, int $size): int
    {
        $bits = $size * 8;

        return $v >= 1 << ($bits - 1) ? $v - (1 << $bits) : $v;
    }

    /**
     * Returns the low $size bytes of $v in the buffer order.
     */
    private function encode(int $v, int $size): string
    {
        $bytes = '';

        for ($i = $size - 1; $i >= 0; --$i) {
            $bytes .= \chr(($v >> ($i * 8)) & 0xFF);
        }

        return $this->ordered($bytes);
    }

    /**
     * Converts big-endian bytes to the buffer order and back.
     */
    private function ordered(string $bytes): string
    {
        return $this->order === Order::Big ? $bytes : strrev($bytes);
    }

    private function patch(int $mark, string $bytes): void
    {
        foreach (str_split($bytes) as $i => $byte) {
            $pos = $mark - $this->consumed + $i;

            // bytes that have already been read are not visible anymore
            if ($pos >= 0) {
                $this->bytes[$pos] = $byte;
            }
        }
    }
}
