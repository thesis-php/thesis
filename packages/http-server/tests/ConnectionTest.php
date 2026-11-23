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
use Thesis\HttpServer\Stub\RecordingWriter;
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
            });
            Assert::same(['/first', '/second'], $paths);
            Assert::same($expected, $output->bytes);
        }
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

        Connection::serve($exchange, $exchange, static fn(Request $request) => new Response(body: $request->body));
        Assert::true(str_ends_with($exchange->output, "1\r\na\r\n1\r\nb\r\n0\r\n\r\n"));
    }

    public function headWithRepresentationLengthBeforeGet(): void
    {
        $input = new StringReader("HEAD / HTTP/1.1\r\nHost: local\r\n\r\nGET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter();
        Connection::serve($input, $output, static fn(Request $request) => $request->method === Method::HEAD
            ? new Response(headers: new Headers(['content-length' => '5']))
            : new Response(body: new StringReader('hello')));

        Assert::same($output->writes, 2);
        Assert::same(
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello",
            $output->bytes,
        );
    }

    public function partiallyReadStringBodyCommitsOnlyTheAvailableBytes(): void
    {
        $input = new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter();
        $body = new StringReader('abc');
        $body->read(1);
        Expect::exception(ReadFailed::class);

        try {
            Connection::serve($input, $output, static fn() => new Response(body: $body));
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
        Connection::serve($input, $output, static fn() => new Response(body: $body));
        Assert::same("HTTP/1.0 200 OK\r\n\r\nhello", $output->bytes);
    }
}
