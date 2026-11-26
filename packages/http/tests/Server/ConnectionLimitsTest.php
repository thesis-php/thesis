<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Instant;
use Thesis\Dispatcher;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Internal\Connection;
use Thesis\Http\Server\Stub\RecordingWriter;
use Thesis\Http\Server\Stub\TimedReader;
use Thesis\Io\StringReader;
use Thesis\Io\WriteFailed;
use Thesis\TestoPlugin\VirtualTime;
use function Thesis\Http\serverContext;
use function Thesis\Http\withoutResponseDate;
use function Thesis\Time\sec;

#[Test]
#[Covers(Connection::class)]
final class ConnectionLimitsTest
{
    public function eventsShareTheRejectedRequestWithoutReadingItsBody(): void
    {
        foreach ([413 => '100-continue', 417 => 'unsupported'] as $status => $expect) {
            $started = $ended = null;
            $onStart = static function (Event\RequestStarted $event) use (&$started) {
                $started = $event->request;
            };
            $onEnd = static function (Event\RequestEnded $event) use (&$ended) {
                $ended = $event;
            };
            $output = new RecordingWriter();
            $connection = new Connection(
                input: new StringReader("POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 2\r\nExpect: {$expect}\r\n\r\n"),
                output: $output,
                config: new Config('/test.sock', maxBodyBytes: $status === 413 ? 1 : 2),
                requestStarted: new Dispatcher([$onStart]),
                requestEnded: new Dispatcher([$onEnd]),
            );
            $connection->run(
                context: serverContext(),
                handler: static fn() => throw new \LogicException('Rejected request reached the handler.'),
            );

            \assert($ended instanceof Event\RequestEnded);
            Assert::same($ended->request, $started);
            Assert::same($ended->request->headers->get('expect'), $expect);
            Assert::same($ended->status, $status);
            Assert::true($ended->responseWritten);
            Assert::string($output->bytes)->startsWith("HTTP/1.1 {$status} ");
        }
    }

    #[VirtualTime]
    public function headDeadlineCoversMultipleReads(): void
    {
        $input = new TimedReader([
            [sec(2), "GET / HTTP/1.1\r\n"],
            [sec(2), "Host: local\r\n\r\n"],
        ]);
        $output = new RecordingWriter();
        $start = Instant::now();

        $status = null;
        $observe = static function (Event\RequestHeadRejected $event) use ($output, &$status) {
            $status = $event->status;
            Assert::same($output->writes, 0);
        };
        $dispatcher = new Dispatcher([$observe]);

        $connection = new Connection(
            input: $input,
            output: $output,
            config: new Config('/test.sock', headTimeout: sec(3)),
            dispatcher: $dispatcher,
        );
        $connection->run(
            context: serverContext(),
            handler: static fn() => throw new \LogicException('An incomplete head must not reach the handler.'),
        );

        Assert::same($status, 408);
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

        $connection = new Connection($input, $output, new Config('/test.sock', bodyIdleTimeout: sec(3)));
        $connection->run(serverContext(), static function (Request $request) {
            $body = $request->takeBody();
            Assert::same($body->read(1) . $body->read(1), 'ab');

            return new Response();
        });

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

        $connection = new Connection($input, $output, new Config('/test.sock', bodyIdleTimeout: sec(1)));
        $connection->run(serverContext(), static function (Request $request) {
            $request->takeBody()->read(1);

            return new Response();
        });

        Assert::string($output->bytes)->startsWith('HTTP/1.1 408 ');
    }

    #[VirtualTime]
    public function slowResponseWriteFails(): void
    {
        $input = new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $output = new RecordingWriter(sec(2));

        Expect::exception(WriteFailed::class)->withMessage('The response write did not complete in time.');

        $connection = new Connection($input, $output, new Config('/test.sock', writeTimeout: sec(1)));
        $connection->run(serverContext(), static fn() => new Response());
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

        $connection = new Connection($input, $output, new Config('/test.sock', idleTimeout: sec(1)));
        $connection->run(serverContext(), static fn() => new Response());

        Assert::equals(Instant::now()->since($start), sec(1));
        Assert::same(withoutResponseDate($output->bytes), "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
    }

    public function rejectsAMethodThatConsumesTheHeadLimit(): void
    {
        $output = new RecordingWriter();
        $connection = new Connection(
            input: new StringReader(str_repeat('A', 128)),
            output: $output,
            config: new Config('/test.sock', maxHeadBytes: 64),
        );
        $connection->run(
            context: serverContext(),
            handler: static fn() => throw new \LogicException('Oversized method reached the handler.'),
        );

        Assert::string($output->bytes)->startsWith('HTTP/1.1 501 ');
    }

    public function headLimitIncludesTheFinalEmptyLine(): void
    {
        $head = "GET / HTTP/1.1\r\nHost: local\r\nConnection: close\r\n\r\n";

        foreach ([\strlen($head) - 1 => 431, \strlen($head) => 200, \strlen($head) + 1 => 200] as $limit => $status) {
            $output = new RecordingWriter();

            $connection = new Connection(
                input: new StringReader($head),
                output: $output,
                config: new Config('/test.sock', maxHeadBytes: $limit),
            );
            $connection->run(serverContext(), static fn() => new Response());

            Assert::string($output->bytes)->startsWith("HTTP/1.1 {$status} ");
        }
    }

    public function limitCountsChunkDataAndAllowsTrailersAtTheBoundary(): void
    {
        $wire = "POST / HTTP/1.1\r\nHost: local\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n"
            . "2\r\nab\r\n0\r\nX-End: yes\r\n\r\n";

        foreach ([1 => 413, 2 => 200, 3 => 200] as $limit => $status) {
            $output = new RecordingWriter();

            $connection = new Connection(
                input: new StringReader($wire),
                output: $output,
                config: new Config('/test.sock', maxBodyBytes: $limit),
            );
            $connection->run(serverContext(), static function (Request $request) {
                $reader = $request->takeBody();
                $body = '';

                while (($chunk = $reader->read(2)) !== null) {
                    $body .= $chunk;
                }

                Assert::same($body, 'ab');
                Assert::same($reader->trailers()->get('x-end'), 'yes');

                return new Response();
            });

            Assert::string($output->bytes)->startsWith("HTTP/1.1 {$status} ");
        }
    }

    public function rejectsOversizedFixedBodyBeforeContinue(): void
    {
        $wire = "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 2\r\nExpect: 100-continue\r\n\r\n";
        $output = new RecordingWriter();

        $connection = new Connection(new StringReader($wire), $output, new Config('/test.sock', maxBodyBytes: 1));
        $connection->run(
            context: serverContext(),
            handler: static fn() => throw new \LogicException('An oversized body must not reach the handler.'),
        );

        Assert::string($output->bytes)->startsWith('HTTP/1.1 413 ');
    }

    public function defaultAllowsMoreThanAThousandRequestsOnOneConnection(): void
    {
        $wire = "GET / HTTP/1.1\r\nHost: local\r\n\r\n";
        $output = new RecordingWriter();

        $connection = new Connection(new StringReader(str_repeat($wire, 1001)), $output, new Config('/test.sock'));
        $connection->run(serverContext(), static fn() => new Response());

        Assert::same(substr_count($output->bytes, 'HTTP/1.1 200 OK'), 1001);
        Assert::string($output->bytes)->notContains('Connection: close');
    }

    public function requestLimitClosesBeforeDispatchingBufferedRequest(): void
    {
        $wire = "GET / HTTP/1.1\r\nHost: local\r\n\r\n";
        $output = new RecordingWriter();

        $connection = new Connection(
            input: new StringReader(str_repeat($wire, 3)),
            output: $output,
            config: new Config('/test.sock', maxRequestsPerConnection: 2),
        );
        $connection->run(serverContext(), static fn() => new Response());

        Assert::same(
            actual: withoutResponseDate($output->bytes),
            expected: "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
            . "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }
}
