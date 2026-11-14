# Thesis Io

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/io/php)](https://packagist.org/packages/thesis/io)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/io)](https://github.com/thesis-php/io/releases)

Fast binary I/O for PHP: an in-memory byte buffer with typed big/little-endian encoding and varints,
plus minimal `Reader` / `Writer` / `Closer` interfaces for streams.

It is the byte layer for binary protocol implementations (AMQP, Kafka, protobuf, gRPC, NATS, ...):
codecs work on a `Buffer`, connections move bytes between a `Buffer` and a socket.

## Installation

```shell
composer require thesis/io
```

Requires 64-bit PHP 8.5+. No extensions.

## Usage

### Encoding

```php
use Thesis\Io\Buffer;
use Thesis\Io\Order;

$buffer = new Buffer(); // Order::Network (big endian) by default

$buffer
    ->writeUint8(1)
    ->writeUint16(0)
    ->writeInt32(-200)
    ->writeDouble(2.5)
    ->writeUvarint(300)
    ->write('raw bytes');

$bytes = $buffer->drain(); // returns all unread bytes and clears the buffer
```

### Decoding

```php
$buffer = new Buffer(Order::Little, $bytes);

$type = $buffer->readUint8();
$value = $buffer->readInt64();
$payload = $buffer->read(16);  // exactly 16 bytes
$header = $buffer->peek(4);    // does not advance the cursor
$buffer->skip(2);
$nested = $buffer->slice(32);  // next 32 bytes as a separate Buffer, e.g. a nested message
$left = $buffer->count();      // number of unread bytes; count($buffer) works too, but is slower
```

Reading past the end throws `Thesis\Io\UnexpectedEof` and leaves the cursor where it was.

### Length prefixes known only after writing

```php
$mark = $buffer->reserve(4); // 4 zero bytes to be patched later

foreach ($table as $key => $value) {
    // write entries...
}

$buffer->patchUint32($mark, $buffer->sizeSince($mark) - 4);
```

`patchUint8()`, `patchUint16()`, `patchUint32()` and `patchUint64()` overwrite bytes in place without copying the buffer.
Marks are invalidated by `fill()`, `flush()` and `drain()`.

### Varints

```php
$buffer->writeUvarint(300);  // unsigned LEB128: protobuf varint, Kafka unsigned varint
$buffer->writeVarint(-1);    // zigzag: protobuf sint32/sint64, Kafka varint/varlong

$buffer->readUvarint();
$buffer->readVarint();
```

### Streams

`Reader::read(int $max)` returns from 1 to `$max` bytes, throws `UnexpectedEof` at the end of the stream
and `ReadFailed` if the read fails otherwise (a connection reset),
`Writer::write(string $bytes)` writes all the bytes. `Buffer` turns them into exact, buffered reads and batched writes:

```php
use Thesis\Io\Buffer;
use Thesis\Io\Stream;

$socket = new Stream(stream_socket_client('tcp://localhost:5672'));
$in = new Buffer();
$out = new Buffer();

// write a frame and send it in one call
$out->writeUint8(1)->writeUint16(0)->writeUint32(\strlen($payload))->write($payload)->writeUint8(0xCE);
$out->flush($socket);

// read a frame: fill() reads in large chunks until enough bytes are buffered
$in->fill($socket, 7);
$type = $in->readUint8();
$channel = $in->readUint16();
$size = $in->readUint32();
$in->fill($socket, $size + 1);
$payload = $in->read($size);
```

Line-based protocols:

```php
// the bytes before the delimiter, or null if the delimiter is not buffered yet
$line = $in->readUntil("\r\n");

if ($line === null) {
    // read from the socket only when the buffered bytes do not contain a whole line
    $in->fillUntil($socket, "\r\n"); // throws \OverflowException if no delimiter within 64 KiB
    $line = $in->readUntil("\r\n");
}
```

`Stream` adapts any blocking PHP stream resource to `Reader`, `Writer` and `Closer`.
It raises the PHP stream chunk size to 64 KiB, so that a single read from a socket is not capped at 8 KiB.

## Supported types

| Methods                        | PHP type | Size, bytes | Range                                                                                   |
|--------------------------------|----------|-------------|-----------------------------------------------------------------------------------------|
| `readInt8` / `writeInt8`       | `int`    | 1           | `−128 .. 127`                                                                           |
| `readUint8` / `writeUint8`     | `int`    | 1           | `0 .. 255`                                                                              |
| `readInt16` / `writeInt16`     | `int`    | 2           | `−32768 .. 32767`                                                                       |
| `readUint16` / `writeUint16`   | `int`    | 2           | `0 .. 65535`                                                                            |
| `readInt32` / `writeInt32`     | `int`    | 4           | `−2147483648 .. 2147483647`                                                             |
| `readUint32` / `writeUint32`   | `int`    | 4           | `0 .. 4294967295`                                                                       |
| `readInt64` / `writeInt64`     | `int`    | 8           | `−2⁶³ .. 2⁶³−1`                                                                         |
| `readUint64` / `writeUint64`   | `int`    | 8           | `0 .. 2⁶³−1`, see below                                                                 |
| `readFloat` / `writeFloat`     | `float`  | 4           | [32-bit IEEE 754](https://en.wikipedia.org/wiki/Single-precision_floating-point_format) |
| `readDouble` / `writeDouble`   | `float`  | 8           | [64-bit IEEE 754](https://en.wikipedia.org/wiki/Double-precision_floating-point_format) |
| `readUvarint` / `writeUvarint` | `int`    | 1 .. 10     | 64-bit pattern, see below                                                               |
| `readVarint` / `writeVarint`   | `int`    | 1 .. 10     | `−2⁶³ .. 2⁶³−1`, zigzag                                                                 |

## Design decisions

### Read cursor instead of cutting the string

Reads advance an offset instead of `substr()`-ing the consumed bytes away, so every read is O(1)
regardless of the buffer size. Consumed bytes are discarded lazily in `fill()`: immediately when everything
has been read, otherwise once they reach 64 KiB.

### Byte order per buffer

A protocol uses one byte order, so it is fixed in the constructor instead of being an argument of every call.
`pack()`/`unpack()` formats are selected once, and there is no `match` on the hot path.

### Native `int` for 64-bit values

The package requires 64-bit PHP, where `int` is exactly int64. `readUint64()` throws `\OverflowException`
for values above `PHP_INT_MAX` instead of silently returning a negative number. Varints keep the raw 64-bit pattern:
`readUvarint()` returns uint64 values above `PHP_INT_MAX` as negative `int`, the way Java's `long` does,
so that a codec can decide how to represent them.

### Range checks via `assert()`

Write methods accept plain `int` and check the range with [`assert()`](https://www.php.net/manual/en/function.assert.php):
full validation in development and zero overhead in production with `zend.assertions = -1`.

### No narrow types and no value objects

8/16/32-bit integers are native `int`: no allocations and no method calls per value,
which matters in the tight loops of protocol parsing.
