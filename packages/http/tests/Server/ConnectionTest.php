<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Psr\Clock\ClockInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Headers;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Internal\Connection;
use Thesis\Http\Server\Internal\GzipBody;
use Thesis\Http\Server\Internal\Http1\ScriptedReader;
use Thesis\Http\Server\Stub\PrefixedBody;
use Thesis\Http\Server\Stub\ReaderBody;
use Thesis\Http\Server\Stub\RecordingWriter;
use Thesis\Http\Server\Stub\TimedReader;
use Thesis\Http\StringBody;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\StreamClosed;
use Thesis\Io\StringReader;
use Thesis\Io\Writer;
use function Thesis\Http\serverContext;
use function Thesis\Http\withoutResponseDate;

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

                public function read(int $max): ?string
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
            $connection = new Connection($input, $output, $config);
            $connection->run(serverContext(), static function (Request $request) use (&$paths) {
                $paths[] = $request->uri->getRawPath();

                return $request->echo();
            });
            Assert::same(['/first', '/second'], $paths);
            Assert::same($expected, withoutResponseDate($output->bytes));
        }
    }

    public function aRepeatedHeadLeavesItsNewBodyAndTheNextRequestInTheBuffer(): void
    {
        $head = "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 2\r\n\r\n";
        $last = "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\n";
        $input = new TimedReader([[null, $head . 'ab'], [null, $head . 'cd' . $last . 'xyz']]);
        $output = new RecordingWriter();
        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(serverContext(), static fn(Request $request) => new Response(body: $request->takeBody()));

        $short = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n";
        Assert::same(
            actual: withoutResponseDate($output->bytes),
            expected: $short . 'ab' . $short . 'cd' . "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nxyz",
        );
    }

    public function writesBufferedEchoBeforeTheNextRequest(): void
    {
        $input = new StringReader("POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\none"
            . "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\ntwo");
        $output = new RecordingWriter();
        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(serverContext(), static fn(Request $request) => new Response(body: $request->takeBody()));

        $head = "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\n";
        Assert::same(withoutResponseDate($output->bytes), $head . 'one' . $head . 'two');
        Assert::same($output->writes, 2);
    }

    public function matchingResponseFieldsCanAcquireTrailers(): void
    {
        $request = "GET / HTTP/1.1\r\nHost: local\r\n\r\n";
        $input = new StringReader($request . $request);
        $output = new RecordingWriter();
        $requests = 0;
        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(serverContext(), static function () use (&$requests) {
            if (++$requests === 1) {
                return new Response(body: 'a');
            }

            return new Response(body: new StringBody('a', new Headers(['X-End' => 'yes'])));
        });

        Assert::same($output->writes, 3);
        Assert::same(withoutResponseDate($output->bytes), "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\na"
            . "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n1\r\na\r\n0\r\nX-End: yes\r\n\r\n");
    }

    public function sendsTheFinalHeadWhenTheFirstBufferedChunkFails(): void
    {
        $input = new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter();
        $body = new GzipBody(new StringBody('content'));
        $body->close();
        Expect::exception(StreamClosed::class);

        try {
            $connection = new Connection($input, $output, new Config('/test.sock'));
            $connection->run(serverContext(), static fn() => new Response(body: $body));
        } finally {
            Assert::same(withoutResponseDate($output->bytes), "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n");
            Assert::same($output->writes, 1);
        }
    }

    /**
     * @param ?positive-int $size
     */
    #[DataSet([null])]
    #[DataSet([1])]
    public function sendsTheHeadWhenTheBodyStopsBeingBufferedDuringReframing(?int $size): void
    {
        $output = new RecordingWriter();
        $source = new ScriptedReader('a');
        $source->beforeRead = static function () use ($output) {
            Assert::true(str_contains($output->bytes, "Connection: close\r\n\r\n"));
        };
        $body = new ReaderBody($source, $size);
        $clock = new class ($body) implements ClockInterface {
            private int $reads = 0;

            public function __construct(
                private readonly ReaderBody $body,
            ) {}

            public function now(): \DateTimeImmutable
            {
                if (++$this->reads === 2) {
                    $this->body->isBuffered = false;
                }

                return new \DateTimeImmutable('@0');
            }
        };
        $connection = new Connection(
            input: new StringReader("POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 1\r\n\r\nx"),
            output: $output,
            config: new Config('/test.sock'),
            clock: $clock,
        );
        $connection->run(serverContext(), static fn() => new Response(body: $body));
        Assert::false($body->isBuffered);
    }

    public function sendsTheHeadAndFirstChunkBeforeWaitingForMore(): void
    {
        $exchange = new class implements Reader, Writer {
            private int $reads = 0;

            public string $output = '';

            public function read(int $max): string
            {
                if (++$this->reads === 1) {
                    return "POST / HTTP/1.1\r\nHost: local\r\nConnection: close\r\nTransfer-Encoding: chunked\r\n\r\n";
                }

                $head = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n";
                if ($this->reads === 2) {
                    Assert::same(withoutResponseDate($this->output), $head);

                    return "1\r\na\r\n";
                }

                Assert::same(3, $this->reads);
                Assert::same(withoutResponseDate($this->output), $head . "1\r\na\r\n");

                return "1\r\nb\r\n0\r\n\r\n";
            }

            public function write(string $bytes): void
            {
                $this->output .= $bytes;
            }
        };

        $connection = new Connection($exchange, $exchange, new Config('/test.sock'));
        $connection->run(serverContext(), static fn(Request $request) => new Response(body: $request->takeBody()));
        Assert::true(str_ends_with($exchange->output, "1\r\na\r\n1\r\nb\r\n0\r\n\r\n"));
    }

    public function headWithRepresentationLengthBeforeGet(): void
    {
        $input = new StringReader("HEAD / HTTP/1.1\r\nHost: local\r\n\r\nGET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter();
        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(serverContext(), static fn(Request $request) => $request->method === Method::HEAD
            ? new Response(headers: new Headers(['content-length' => '5']))
            : new Response(body: 'hello'));

        Assert::same($output->writes, 2);
        Assert::same(
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello",
            withoutResponseDate($output->bytes),
        );
    }

    public function headSuppressesTheHandlerBodyBeforeGet(): void
    {
        $input = new StringReader("HEAD / HTTP/1.1\r\nHost: local\r\n\r\nGET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter();
        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(serverContext(), static fn() => new Response(body: 'hello'));

        Assert::same($output->writes, 2);
        Assert::same(
            "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello",
            withoutResponseDate($output->bytes),
        );
    }

    public function writesLargeStringBodiesInBoundedBlocks(): void
    {
        $body = str_repeat('a', 65536) . 'tail';
        $output = new RecordingWriter();
        $connection = new Connection(
            input: new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n"),
            output: $output,
            config: new Config('/test.sock'),
        );
        $connection->run(serverContext(), static fn() => new Response(body: $body));

        Assert::same($output->writes, 3);
        Assert::same(withoutResponseDate($output->bytes), "HTTP/1.1 200 OK\r\nContent-Length: 65540\r\n\r\n{$body}");
    }

    public function partiallyReadStringBodyCommitsOnlyTheAvailableBytes(): void
    {
        $input = new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter();
        $body = new StringBody('abc');
        $body->read(1);
        Expect::exception(ReadFailed::class);

        try {
            $connection = new Connection($input, $output, new Config('/test.sock'));
            $connection->run(serverContext(), static fn() => new Response(body: $body));
        } finally {
            Assert::same(withoutResponseDate($output->bytes), "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nbc");
        }
    }

    public function http10EndsUnknownLengthResponseAtConnectionClose(): void
    {
        $input = new StringReader("GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\nGET /next HTTP/1.0\r\n\r\n");
        $output = new RecordingWriter();
        $body = new PrefixedBody(new StringBody('hello'));
        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(serverContext(), static fn() => new Response(body: $body));
        Assert::same("HTTP/1.0 200 OK\r\n\r\n!hello", withoutResponseDate($output->bytes));
    }
}
