<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Instant;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\HttpServer\Internal\Connection;
use Thesis\HttpServer\Stub\RecordingWriter;
use Thesis\HttpServer\Stub\TimedReader;
use Thesis\Io\StringReader;
use Thesis\Io\WriteFailed;
use Thesis\TestoPlugin\VirtualTime;
use function Thesis\Time\sec;

#[Test]
#[Covers(Connection::class)]
final class ConnectionLimitsTest
{
    #[VirtualTime]
    public function headDeadlineCoversMultipleReads(): void
    {
        $input = new TimedReader([
            [sec(2), "GET / HTTP/1.1\r\n"],
            [sec(2), "Host: local\r\n\r\n"],
        ]);
        $output = new RecordingWriter();
        $start = Instant::now();

        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn() => throw new \LogicException('An incomplete head must not reach the handler.'),
            config: new Config('/test.sock', headTimeout: sec(3)),
        );

        Assert::equals(Instant::now()->since($start), sec(3));
        Assert::string($output->bytes)->startsWith('HTTP/1.1 408 ');
    }

    #[VirtualTime]
    public function bodyIdleDeadlineRestartsWhenBytesArrive(): void
    {
        $input = new TimedReader([
            [null, "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 2\r\nConnection: close\r\n\r\n"],
            [sec(2), 'a'],
            [sec(2), 'b'],
        ]);
        $output = new RecordingWriter();
        $start = Instant::now();

        Connection::serve(
            input: $input,
            output: $output,
            handler: static function (Request $request) {
                Assert::same($request->body->read(1) . $request->body->read(1), 'ab');

                return new Response();
            },
            config: new Config('/test.sock', bodyIdleTimeout: sec(3)),
        );

        Assert::equals(Instant::now()->since($start), sec(4));
        Assert::string($output->bytes)->startsWith('HTTP/1.1 200 ');
    }

    #[VirtualTime]
    public function bodyIdleExpiryReturnsRequestTimeout(): void
    {
        $input = new TimedReader([
            [null, "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 1\r\n\r\n"],
            [sec(2), 'a'],
        ]);
        $output = new RecordingWriter();

        Connection::serve(
            input: $input,
            output: $output,
            handler: static function (Request $request) {
                $request->body->read(1);

                return new Response();
            },
            config: new Config('/test.sock', bodyIdleTimeout: sec(1)),
        );

        Assert::string($output->bytes)->startsWith('HTTP/1.1 408 ');
    }

    #[VirtualTime]
    public function slowResponseWriteFails(): void
    {
        $input = new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter(sec(2));

        Expect::exception(WriteFailed::class)->withMessage('The response write did not complete in time.');

        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn() => new Response(),
            config: new Config('/test.sock', writeTimeout: sec(1)),
        );
    }

    #[VirtualTime]
    public function idleConnectionClosesWhileWaitingForNextRequest(): void
    {
        $input = new TimedReader([
            [null, "GET / HTTP/1.1\r\nHost: local\r\n\r\n"],
            [sec(2), 'G'],
        ]);
        $output = new RecordingWriter();
        $start = Instant::now();

        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn() => new Response(),
            config: new Config('/test.sock', idleTimeout: sec(1)),
        );

        Assert::equals(Instant::now()->since($start), sec(1));
        Assert::same($output->bytes, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
    }

    public function headLimitIncludesTheFinalEmptyLine(): void
    {
        $head = "GET / HTTP/1.1\r\nHost: local\r\nConnection: close\r\n\r\n";

        foreach ([\strlen($head) - 1 => 431, \strlen($head) => 200, \strlen($head) + 1 => 200] as $limit => $status) {
            $output = new RecordingWriter();

            Connection::serve(
                input: new StringReader($head),
                output: $output,
                handler: static fn() => new Response(),
                config: new Config('/test.sock', maxHeadBytes: $limit),
            );

            Assert::string($output->bytes)->startsWith("HTTP/1.1 {$status} ");
        }
    }

    public function limitCountsChunkDataAndAllowsTrailersAtTheBoundary(): void
    {
        $wire = "POST / HTTP/1.1\r\nHost: local\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n"
            . "2\r\nab\r\n0\r\nX-End: yes\r\n\r\n";

        foreach ([1 => 413, 2 => 200, 3 => 200] as $limit => $status) {
            $output = new RecordingWriter();

            Connection::serve(
                input: new StringReader($wire),
                output: $output,
                handler: static function (Request $request) {
                    $body = '';

                    while (($chunk = $request->body->read(2)) !== null) {
                        $body .= $chunk;
                    }

                    Assert::same($body, 'ab');
                    Assert::same($request->body->trailers?->get('x-end'), 'yes');

                    return new Response();
                },
                config: new Config('/test.sock', maxBodyBytes: $limit),
            );

            Assert::string($output->bytes)->startsWith("HTTP/1.1 {$status} ");
        }
    }

    public function rejectsOversizedFixedBodyBeforeContinue(): void
    {
        $wire = "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 2\r\nExpect: 100-continue\r\n\r\n";
        $output = new RecordingWriter();

        Connection::serve(
            input: new StringReader($wire),
            output: $output,
            handler: static fn() => throw new \LogicException('An oversized body must not reach the handler.'),
            config: new Config('/test.sock', maxBodyBytes: 1),
        );

        Assert::string($output->bytes)->startsWith('HTTP/1.1 413 ');
    }

    public function defaultAllowsMoreThanAThousandRequestsOnOneConnection(): void
    {
        $wire = "GET / HTTP/1.1\r\nHost: local\r\n\r\n";
        $output = new RecordingWriter();

        Connection::serve(
            input: new StringReader(str_repeat($wire, 1001)),
            output: $output,
            handler: static fn() => new Response(),
            config: new Config('/test.sock'),
        );

        Assert::same(substr_count($output->bytes, 'HTTP/1.1 200 OK'), 1001);
        Assert::string($output->bytes)->notContains('Connection: close');
    }

    public function requestLimitClosesBeforeDispatchingBufferedRequest(): void
    {
        $wire = "GET / HTTP/1.1\r\nHost: local\r\n\r\n";
        $output = new RecordingWriter();

        Connection::serve(
            input: new StringReader(str_repeat($wire, 3)),
            output: $output,
            handler: static fn() => new Response(),
            config: new Config('/test.sock', maxRequestsPerConnection: 2),
        );

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
            . "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }
}
