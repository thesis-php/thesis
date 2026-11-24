<?php

declare(strict_types=1);

namespace Thesis\HttpProtocol\Http1;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Headers;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Io\Buffer;
use Thesis\Io\ConcurrentRead;
use Thesis\Io\ReadFailed;
use Thesis\Io\StringReader;
use Thesis\Io\UnexpectedEof;
use Uri\Rfc3986\Uri;

#[Test]
#[Covers(ChunkedReader::class)]
final class ChunkedTest
{
    private const string HELLO_WORLD = "5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n";

    public function rejectsAConcurrentReadWhileReadingTheChunkSize(): void
    {
        $buffer = new Buffer();
        $source = new ScriptedReader("hello\r\n0\r\n\r\n");
        $reader = new ChunkedReader($buffer, $source);
        $source->beforeRead = static function () {
            \Fiber::suspend();
        };
        $reading = new \Fiber(static fn() => $reader->read(1));
        $reading->start();
        $buffer->write("5\r\n");
        $source->beforeRead = null;
        Expect::exception(ConcurrentRead::class)->withMessage('A read is already in progress on the body.');

        try {
            $reader->read(1);
        } finally {
            $reading->resume();
            Assert::same($reading->getReturn(), 'h');
            Assert::same(readAll($reader), 'ello');
        }
    }

    public function encodesChunkWithItsSizeInHexadecimal(): void
    {
        Assert::same(encodeChunk('hello'), "5\r\nhello\r\n");
        Assert::same(encodeChunk(str_repeat('a', 255)), "ff\r\n" . str_repeat('a', 255) . "\r\n");
        Assert::same(encodeChunk("\r\n"), "2\r\n\r\n\r\n");
    }

    public function encodesLastChunk(): void
    {
        Assert::same(encodeLastChunk(), "0\r\n\r\n");
    }

    public function encodesLastChunkWithTrailers(): void
    {
        $trailers = new Headers(['x-checksum' => 'abc', 'grpc-status' => ['0', '1']]);

        Assert::same(encodeLastChunk($trailers), "0\r\nX-Checksum: abc\r\nGrpc-Status: 0\r\nGrpc-Status: 1\r\n\r\n");
    }

    #[DataProvider('fieldsNotInTrailers')]
    public function refusesTrailerThatFramesOrRoutesMessage(string $name): void
    {
        Assert::same(
            actual: failure(static fn() => encodeLastChunk(new Headers(['x-first' => 'a', $name => 'b']))),
            expected: "The field \"{$name}\" must not be in trailers.",
        );
    }

    #[DataProvider('fieldsNotInTrailers')]
    public function rejectsTrailerThatFramesOrRoutesMessage(string $name): void
    {
        $chunked = chunked("0\r\nX-First: a\r\n" . strtoupper($name) . ": b\r\n\r\n");

        Assert::same(
            actual: rejection(static fn() => $chunked->read(1)),
            expected: "400: The field \"{$name}\" must not be in trailers.",
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fieldsNotInTrailers(): iterable
    {
        yield 'content-length' => ['content-length'];
        yield 'transfer-encoding' => ['transfer-encoding'];
        yield 'connection' => ['connection'];
        yield 'keep-alive' => ['keep-alive'];
        yield 'upgrade' => ['upgrade'];
        yield 'host' => ['host'];
    }

    public function decodesChunks(): void
    {
        $chunked = chunked(self::HELLO_WORLD);

        Assert::true($chunked->mayHaveTrailers);
        Assert::same($chunked->readAll(), 'hello world');
        Assert::true($chunked->isComplete);
        Assert::false($chunked->mayHaveTrailers);
        Assert::same(lines($chunked->trailers()), []);
    }

    public function rejectsReadingTrailersBeforeTheLastChunk(): void
    {
        $reader = chunked(self::HELLO_WORLD);
        $reader->read(1);
        Expect::exception(\LogicException::class)->withMessage('The trailer fields are still pending.');

        $reader->trailers();
    }

    public function readsAtMostMaxBytesAndNeverAcrossChunks(): void
    {
        $chunked = chunked(self::HELLO_WORLD);

        Assert::same(
            actual: [$chunked->read(3), $chunked->read(3), $chunked->read(4), $chunked->read(100)],
            expected: ['hel', 'lo', ' wor', 'ld'],
        );
        Assert::null($chunked->read(1));
    }

    public function decodesWhatItHasEncoded(): void
    {
        $bytes = encodeChunk('hello') . encodeChunk(str_repeat('a', 1000)) . encodeChunk("\r\n0\r\n\r\n")
            . encodeLastChunk(new Headers(['x-checksum' => 'abc']));
        $chunked = chunked($bytes);

        Assert::same(readAll($chunked), 'hello' . str_repeat('a', 1000) . "\r\n0\r\n\r\n");
        $trailers = $chunked->trailers();
        Assert::same(lines($trailers), [['x-checksum', 'abc']]);
    }

    #[DataProvider('sizes')]
    public function parsesChunkSize(string $size, int $length): void
    {
        $chunked = chunked("{$size}\r\n" . str_repeat('a', $length) . "\r\n0\r\n\r\n");

        Assert::same(readAll($chunked), str_repeat('a', $length));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function sizes(): iterable
    {
        yield 'one digit' => ['9', 9];
        yield 'lowercase' => ['a', 10];
        yield 'uppercase' => ['F', 15];
        yield 'two digits' => ['1f', 31];
        yield 'mixed case' => ['aB', 171];
        yield 'leading zeros' => ['000000000000010', 16];
        yield 'extension' => ['5;name=value', 5];
        yield 'extension with quotes and tab' => ["5;name=\"a\tb\";other", 5];
        yield 'extension with whitespace' => ["5 \t; name = token ; flag", 5];
        yield 'extension with escaped quote' => ['5;name="a\";b"', 5];
    }

    public function acceptsGreatestChunkSize(): void
    {
        $chunked = chunked("fffffffffffffff\r\nabc");

        Assert::same($chunked->read(3), 'abc');
    }

    /**
     * @param positive-int $chunkSize
     */
    #[DataProvider('readerChunkSizes')]
    public function decodesBytesThatArriveInPieces(int $chunkSize): void
    {
        $bytes = encodeChunk('hello') . encodeChunk(' world') . "0\r\nX-A: 1\r\nX-B: 2\r\n\r\nNEXT";
        $buffer = new Buffer();
        $chunked = new ChunkedReader($buffer, new ScriptedReader($bytes, $chunkSize));

        Assert::same(readAll($chunked, max: 4), 'hello world');
        $trailers = $chunked->trailers();
        Assert::same(lines($trailers), [['x-a', '1'], ['x-b', '2']]);
    }

    /**
     * @return iterable<array{positive-int}>
     */
    public static function readerChunkSizes(): iterable
    {
        for ($chunkSize = 1; $chunkSize <= 12; ++$chunkSize) {
            yield [$chunkSize];
        }
    }

    public function leavesBytesAfterBodyInBuffer(): void
    {
        $buffer = new Buffer();
        $reader = new ScriptedReader(self::HELLO_WORLD . 'GET /next HTTP/1.1');
        $chunked = new ChunkedReader($buffer, $reader);

        readAll($chunked);

        Assert::same($buffer->drain(), 'GET /next HTTP/1.1');
    }

    public function readsNothingAfterLastChunkAndTrailers(): void
    {
        $buffer = new Buffer();
        $reader = new ScriptedReader(self::HELLO_WORLD, chunkSize: 1);
        $chunked = new ChunkedReader($buffer, $reader);
        readAll($chunked);
        $requests = \count($reader->requests);

        Assert::null($chunked->read(1));

        Assert::same(\count($reader->requests), $requests);
        Assert::same($requests, \strlen(self::HELLO_WORLD));
    }

    public function readsBytesAlreadyInBufferFirst(): void
    {
        $buffer = new Buffer(bytes: "5\r\nhel");
        $reader = new ScriptedReader("lo\r\n0\r\n\r\n");
        $chunked = new ChunkedReader($buffer, $reader);

        Assert::same([$chunked->read(100), $chunked->read(100)], ['hel', 'lo']);
    }

    public function asksReaderOnlyForRestOfChunkWhenBufferIsEmpty(): void
    {
        $buffer = new Buffer(bytes: "a\r\n");
        $reader = new ScriptedReader('0123456789' . "\r\n0\r\n\r\n", chunkSize: 4);
        $chunked = new ChunkedReader($buffer, $reader);

        Assert::same([$chunked->read(100), $chunked->read(3), $chunked->read(100)], ['0123', '456', '789']);
        Assert::same($reader->requests, [10, 3, 3]);
    }

    public function exposesTrailersOnTheRequestAfterTheReaderEnds(): void
    {
        $chunked = chunked("3\r\nabc\r\n0\r\nX-Checksum: 900150983cd24fb0\r\n\r\n");
        $request = new Request(
            method: 'POST',
            uri: new Uri('http://example.com/'),
            body: $chunked,
        );

        $body = $request->takeBody();
        Assert::same($body, $chunked);
        Assert::false($chunked->isComplete);
        Assert::same($body->read(3), 'abc');
        Assert::false($chunked->isComplete);
        Assert::same(readAll($body), '');

        Assert::true($chunked->isComplete);
        Assert::same($body->trailers()->get('x-checksum'), '900150983cd24fb0');
    }

    public function readsRemainingRequestBodyThroughItsTrailers(): void
    {
        $buffer = new Buffer(bytes: "3\r\nabc\r\n0\r\nX-End: yes\r\n\r\nnext");
        $body = new ChunkedReader($buffer, new StringReader(''));
        $request = new Request('POST', new Uri('/'), body: $body);

        $reader = $request->takeBody();
        Assert::same($reader->read(1), 'a');
        Assert::false($body->isComplete);
        Assert::same(readAll($reader), 'bc');
        $trailers = $reader->trailers();
        Assert::same($trailers->get('x-end'), 'yes');
        Assert::same($reader->trailers(), $trailers);
        Assert::same($buffer->drain(), 'next');
    }

    public function truncatedBodyFailsWhileReadingTheWholePayload(): void
    {
        $response = new Response(body: chunked("3\r\nab"));
        Expect::exception(ReadFailed::class);
        $response->takeBody()->readAll();
    }

    public function goesOnAfterReadThatHasFailed(): void
    {
        $bytes = encodeChunk('hello') . encodeChunk(' world') . "0\r\nX-A: 1\r\nX-B: 2\r\n\r\n";
        $reader = new ScriptedReader($bytes, chunkSize: 1);
        $reader->failsEveryOtherRead = true;
        $chunked = new ChunkedReader(new Buffer(), $reader);
        $decoded = '';
        $failures = 0;

        while (true) {
            try {
                $chunk = $chunked->read(3);
                if ($chunk === null) {
                    break;
                }

                $decoded .= $chunk;
            } catch (ReadFailed $failed) {
                Assert::same($failed->getMessage(), 'Reset.');
                ++$failures;

            }
        }

        Assert::same($decoded, 'hello world');
        $trailers = $chunked->trailers();
        Assert::same(lines($trailers), [['x-a', '1'], ['x-b', '2']]);
        Assert::same($failures, \strlen($bytes));
    }

    #[DataProvider('bodiesCutShort')]
    public function failsWhenConnectionEndsBeforeEndOfBody(string $bytes): void
    {
        $chunked = chunked($bytes);
        Expect
            ::exception(ReadFailed::class)
            ->withMessage('The connection has ended before the last chunk of the body.')
        ;

        readAll($chunked);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesCutShort(): iterable
    {
        yield 'nothing' => [''];
        yield 'in size' => ['5'];
        yield 'after CR of size' => ["5\r"];
        yield 'before data' => ["5\r\n"];
        yield 'in data' => ["5\r\nhel"];
        yield 'after data' => ["5\r\nhello"];
        yield 'after CR of data' => ["5\r\nhello\r"];
        yield 'before last chunk' => ["5\r\nhello\r\n"];
        yield 'in last chunk' => ["5\r\nhello\r\n0"];
        yield 'before trailers' => ["5\r\nhello\r\n0\r\n"];
        yield 'in trailer' => ["5\r\nhello\r\n0\r\nX-A: 1"];
        yield 'after trailer' => ["5\r\nhello\r\n0\r\nX-A: 1\r\n"];
    }

    public function failsOnceMoreAfterConnectionHasEnded(): void
    {
        $chunked = chunked("5\r\nhel");
        $chunked->read(3);

        foreach ([1, 2] as $_) {
            try {
                $chunked->read(3);
                Assert::fail('The connection has ended.');
            } catch (ReadFailed $failed) {
                Assert::true($failed->getPrevious() instanceof UnexpectedEof);
            }
        }
    }

    #[DataProvider('malformedChunks')]
    public function rejectsBytesThatAreNotChunks(string $bytes, string $rejection): void
    {
        $chunked = chunked($bytes);

        Assert::same(rejection(static fn() => readAll($chunked)), $rejection);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedChunks(): iterable
    {
        $size = '400: A chunk size must be a hexadecimal number of at most 15 digits, got %s.';
        $end = '400: The data of a chunk must be followed by CRLF, got %s.';

        yield 'empty size' => ["\r\nhello\r\n", \sprintf($size, '""')];
        yield 'not hexadecimal' => ["xyz\r\n", \sprintf($size, '"xyz"')];
        yield 'prefix' => ["0x5\r\nhello\r\n", \sprintf($size, '"0x5"')];
        yield 'plus' => ["+5\r\nhello\r\n", \sprintf($size, '"+5"')];
        yield 'minus' => ["-5\r\nhello\r\n", \sprintf($size, '"-5"')];
        yield 'space before' => [" 5\r\nhello\r\n", \sprintf($size, '" 5"')];
        yield 'space after' => ["5 \r\nhello\r\n", \sprintf($size, '"5 "')];
        yield 'empty extension' => ["5;\r\n", \sprintf($size, '"5;"')];
        yield 'missing extension value' => ["5;a=\r\n", \sprintf($size, '"5;a="')];
        yield 'unterminated quoted value' => ["5;a=\"\r\n", \sprintf($size, '"5;a=\""')];
        yield 'letter after' => ["5g\r\nhello\r\n", \sprintf($size, '"5g"')];
        yield 'sixteen digits' => ["0000000000000005\r\nhello\r\n", \sprintf($size, '"0000000000000005"')];
        yield 'bare LF after size' => ["5\nhello\r\n0\r\n\r\n", \sprintf($size, '"5\nhello"')];
        yield 'bare CR in extension' => ["5;a\rb\r\nhello\r\n", \sprintf($size, '"5;a\rb"')];
        yield 'NUL in extension' => ["5;a\0b\r\nhello\r\n", \sprintf($size, '"5;a\000b"')];
        yield 'data longer than size' => ["5\r\nhello!\r\n0\r\n\r\n", \sprintf($end, '"!\r"')];
        yield 'bare LF after data' => ["5\r\nhello\n0\r\n\r\n", \sprintf($end, '"\n0"')];
        yield 'bare CR after data' => ["5\r\nhello\r0\r\n\r\n", \sprintf($end, '"\r0"')];
        yield 'no line end after data' => ["5\r\nhello0\r\n\r\n", \sprintf($end, '"0\r"')];
        yield 'malformed trailer' => [
            "0\r\nX-A : 1\r\n\r\n",
            '400: A field line must be a token, a colon and a value, got "X-A : 1".',
        ];
        yield 'folded trailer' => [
            "0\r\nX-A: 1\r\n 2\r\n\r\n",
            '400: A field line must be a token, a colon and a value, got " 2".',
        ];
    }

    public function keepsMalformedChunkFailureOnLaterReads(): void
    {
        $reader = chunked("invalid\r\n0\r\n\r\n");
        $expected = '400: A chunk size must be a hexadecimal number of at most 15 digits, got "invalid".';

        Assert::same(rejection(static fn() => $reader->read(1)), $expected);
        Assert::same(rejection(static fn() => $reader->read(1)), $expected);
        Assert::false($reader->isComplete);
    }

    public function rejectsExcessTrailerBeforeWaitingForAnotherLine(): void
    {
        $reader = new ChunkedReader(new Buffer(), new ScriptedReader("0\r\nA: 1\r\nB: 2\r\n"), maxTrailers: 1);

        Assert::same(rejection(static fn() => $reader->read(1)), '431: A message must have at most 1 fields, got 2.');
    }

    public function acceptsLineOfChunkSizeAsLongAsLimit(): void
    {
        $chunked = new ChunkedReader(
            buffer: new Buffer(),
            reader: new ScriptedReader("3;abcd\r\nabc\r\n0\r\n\r\n"),
            maxSizeLineLength: 8,
        );

        Assert::same(readAll($chunked), 'abc');
    }

    public function rejectsLineOfChunkSizeLongerThanLimit(): void
    {
        $chunked = new ChunkedReader(
            buffer: new Buffer(),
            reader: new ScriptedReader("3;abcde\r\nabc\r\n0\r\n\r\n"),
            maxSizeLineLength: 8,
        );

        Assert::same(
            actual: rejection(static fn() => readAll($chunked)),
            expected: '400: A chunk size line must be at most 8 bytes.',
        );
    }

    public function rejectsLineOfChunkSizeLongerThan256BytesByDefault(): void
    {
        $extension = static function (int $length) {
            $padding = str_repeat('a', $length - \strlen("3;\r\n"));

            return '3;' . $padding . "\r\nabc\r\n0\r\n\r\n";
        };

        Assert::same(readAll(chunked($extension(256))), 'abc');
        Assert::same(
            actual: rejection(static fn() => readAll(chunked($extension(257)))),
            expected: '400: A chunk size line must be at most 256 bytes.',
        );
    }

    public function checksTheEmptyTrailerLineAgainstItsLimitAfterBufferedData(): void
    {
        $buffer = new Buffer();
        $buffer->write("1\r\na\r\n0\r\n\r\n");
        $reader = new ChunkedReader($buffer, new ScriptedReader(''), maxTrailersLength: 1);

        Assert::same($reader->read(1), 'a');
        Assert::same(rejection(static fn() => $reader->read(1)), '431: The trailers of a chunked body must be at most 1 bytes.');
    }

    public function acceptsTrailersAsLongAsLimit(): void
    {
        $chunked = new ChunkedReader(
            buffer: new Buffer(),
            reader: new ScriptedReader("0\r\nA: 1\r\nB: 2\r\n\r\n"),
            maxTrailersLength: 14,
        );

        readAll($chunked);

        $trailers = $chunked->trailers();
        Assert::same(lines($trailers), [['a', '1'], ['b', '2']]);
    }

    #[DataProvider('longTrailers')]
    public function rejectsTrailersLongerThanLimit(string $trailers): void
    {
        $chunked = new ChunkedReader(new Buffer(), new ScriptedReader("0\r\n{$trailers}\r\n"), maxTrailersLength: 14);

        Assert::same(
            actual: rejection(static fn() => readAll($chunked)),
            expected: '431: The trailers of a chunked body must be at most 14 bytes.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function longTrailers(): iterable
    {
        yield 'one byte over' => ["A: 1\r\nB: 22\r\n"];
        yield 'one line over' => ["A: 1\r\nB: 2\r\nC: 3\r\n"];
        yield 'one long line' => ["A: 1234567890\r\n"];
        yield 'lines that fill limit before empty line' => ["A: 123\r\nB: 12\r\n"];
    }

    public function rejectsTrailersLongerThan16KibibytesByDefault(): void
    {
        $trailers = static function (int $length) {
            $padding = str_repeat('a', $length - \strlen("A: \r\n\r\n"));

            return "0\r\nA: " . $padding . "\r\n\r\n";
        };

        Assert::null(rejection(static fn() => readAll(chunked($trailers(16 * 1024)))));
        Assert::same(
            actual: rejection(static fn() => readAll(chunked($trailers(16 * 1024 + 1)))),
            expected: '431: The trailers of a chunked body must be at most 16384 bytes.',
        );
    }

    public function rejectsMoreTrailersThanLimit(): void
    {
        $bytes = "0\r\nA: 1\r\nB: 2\r\nC: 3\r\n\r\n";

        Assert::null(rejection(
            static fn() => readAll(new ChunkedReader(new Buffer(), new ScriptedReader($bytes), maxTrailers: 3)),
        ));
        Assert::same(
            actual: rejection(
                static fn() => readAll(new ChunkedReader(new Buffer(), new ScriptedReader($bytes), maxTrailers: 2)),
            ),
            expected: '431: A message must have at most 2 fields, got 3.',
        );
    }

    public function rejectsMoreThanHundredTrailersByDefault(): void
    {
        $trailers = static fn(int $count) => "0\r\n" . str_repeat("A: 1\r\n", $count) . "\r\n";

        Assert::null(rejection(static fn() => readAll(chunked($trailers(100)))));
        Assert::same(
            actual: rejection(static fn() => readAll(chunked($trailers(101)))),
            expected: '431: A message must have at most 100 fields, got 101.',
        );
    }
}
