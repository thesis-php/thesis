<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Headers;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\HttpServer\Internal\Connection;
use Thesis\HttpServer\Stub\RecordingWriter;
use Thesis\HttpServer\Stub\TimedReader;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\StringReader;
use Thesis\Io\Writer;

#[Test]
#[Covers(Connection::class)]
final class ConnectionTest
{
    public function echoesTwoRequests(): void
    {
        $wire = "POST /first HTTP/1.1\r\nHost: local\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "1\r\na\r\n1\r\nb\r\n0\r\nX-End: yes\r\n\r\n"
            . "POST /second HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\nConnection: close\r\n\r\nxyz";
        $expected = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n1\r\na\r\n1\r\nb\r\n0\r\nX-End: yes\r\n\r\n"
            . "HTTP/1.1 200 OK\r\nContent-Length: 3\r\nConnection: close\r\n\r\nxyz";
        $config = new Config('/test.sock');

        for ($split = 0; $split <= \strlen($wire); ++$split) {
            $input = new class ($wire, $split) implements Reader {
                private readonly StringReader $source;

                /**
                 * @param non-negative-int $split zero delivers one byte on every read
                 */
                public function __construct(
                    string $wire,
                    private int $split,
                ) {
                    $this->source = new StringReader($wire);
                }

                public function read(int $max): string
                {
                    $bytes = $this->source->read(min($max, max(1, $this->split)));
                    if ($this->split !== 0) {
                        $this->split = PHP_INT_MAX;
                    }

                    return $bytes;
                }
            };
            $output = new RecordingWriter();
            $paths = [];
            Connection::serve($input, $output, static function (Request $request) use (&$paths) {
                $paths[] = $request->uri->getRawPath();

                return new Response(body: $request->body, trailers: static fn() => $request->trailers);
            }, $config);
            Assert::same(['/first', '/second'], $paths);
            Assert::same($expected, $output->bytes);
        }
    }

    public function aRepeatedHeadLeavesItsNewBodyAndTheNextRequestInTheBuffer(): void
    {
        $head = "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 2\r\n\r\n";
        $last = "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\n";
        $input = new TimedReader([[null, $head . 'ab'], [null, $head . 'cd' . $last . 'xyz']]);
        $output = new RecordingWriter();
        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn(Request $request) => new Response(body: $request->body),
            config: new Config('/test.sock'),
        );

        $short = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n";
        Assert::same($short . 'ab' . $short . 'cd' . "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nxyz", $output->bytes);
    }

    public function writesBufferedEchoBeforeTheNextRequest(): void
    {
        $input = new StringReader("POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\none"
            . "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\ntwo");
        $output = new RecordingWriter();
        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn(Request $request) => new Response(body: $request->body),
            config: new Config('/test.sock'),
        );

        $head = "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\n";
        Assert::same($output->bytes, $head . 'one' . $head . 'two');
        Assert::same($output->writes, 2);
    }

    public function sendsFirstChunkBeforeReadingSecond(): void
    {
        $exchange = new class implements Reader, Writer {
            private int $reads = 0;

            public string $output = '';

            public function read(int $max): string
            {
                if (++$this->reads === 1) {
                    return "POST / HTTP/1.1\r\nHost: local\r\nConnection: close\r\nTransfer-Encoding: chunked\r\n\r\n"
                        . "1\r\na\r\n";
                }

                Assert::same(2, $this->reads);
                Assert::same(
                    "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n1\r\na\r\n",
                    $this->output,
                );

                return "1\r\nb\r\n0\r\n\r\n";
            }

            public function write(string $bytes): void
            {
                $this->output .= $bytes;
            }
        };

        Connection::serve(
            input: $exchange,
            output: $exchange,
            handler: static fn(Request $request) => new Response(body: $request->body),
            config: new Config('/test.sock'),
        );
        Assert::true(str_ends_with($exchange->output, "1\r\na\r\n1\r\nb\r\n0\r\n\r\n"));
    }

    public function headWithRepresentationLengthBeforeGet(): void
    {
        $input = new StringReader("HEAD / HTTP/1.1\r\nHost: local\r\n\r\nGET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter();
        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn(Request $request) => $request->method === Method::HEAD
                ? new Response(headers: new Headers(['content-length' => '5']))
                : new Response(body: new StringReader('hello')),
            config: new Config('/test.sock'),
        );

        Assert::same($output->writes, 2);
        Assert::same(
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello",
            $output->bytes,
        );
    }

    public function writesLargeStringBodiesInBoundedBlocks(): void
    {
        $body = str_repeat('a', 65536) . 'tail';
        $output = new RecordingWriter();
        Connection::serve(
            input: new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n"),
            output: $output,
            handler: static fn() => new Response(body: new StringReader($body)),
            config: new Config('/test.sock'),
        );

        Assert::same($output->writes, 3);
        Assert::same($output->bytes, "HTTP/1.1 200 OK\r\nContent-Length: 65540\r\n\r\n{$body}");
    }

    public function partiallyReadStringBodyCommitsOnlyTheAvailableBytes(): void
    {
        $input = new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter();
        $body = new StringReader('abc');
        $body->read(1);
        Expect::exception(ReadFailed::class);

        try {
            Connection::serve($input, $output, static fn() => new Response(body: $body), new Config('/test.sock'));
        } finally {
            Assert::same($output->bytes, "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nbc");
        }
    }

    public function http10EndsUnknownLengthResponseAtConnectionClose(): void
    {
        $input = new StringReader("GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\nGET /next HTTP/1.0\r\n\r\n");
        $output = new RecordingWriter();
        $body = new readonly class implements Reader {
            private StringReader $source;

            public function __construct()
            {
                $this->source = new StringReader('hello');
            }

            public function read(int $max): string
            {
                return $this->source->read($max);
            }
        };
        Connection::serve($input, $output, static fn() => new Response(body: $body), new Config('/test.sock'));
        Assert::same("HTTP/1.0 200 OK\r\n\r\nhello", $output->bytes);
    }
}
