<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Status;
use Thesis\HttpServer\Internal\Connection;
use Thesis\HttpServer\Stub\ContinueReader;
use Thesis\HttpServer\Stub\PrefixedBody;
use Thesis\HttpServer\Stub\RecordingWriter;
use Thesis\Io\ReadFailed;
use Thesis\Io\StringReader;

#[Test]
#[Covers(Connection::class)]
final class ExchangeTest
{
    public function continuesBeforeEchoReadsTheBody(): void
    {
        $output = new RecordingWriter();
        $input = new ContinueReader($output);

        Connection::serve($input, $output, static function (Request $request) use ($output) {
            Assert::same(actual: $output->bytes, expected: '');

            return new Response(body: $request->body);
        }, new Config('/test.sock', maxRequestsPerConnection: 1));

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 100 Continue\r\n\r\n"
                . "HTTP/1.1 200 OK\r\nContent-Length: 3\r\nConnection: close\r\n\r\nabc",
        );
    }

    public function continuesBeforeAResponseThatReadsTheRequestBodyAfterAPrefix(): void
    {
        $output = new RecordingWriter();
        $input = new ContinueReader($output);

        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn(Request $request) => new Response(body: new PrefixedBody($request->body)),
            config: new Config('/test.sock', maxRequestsPerConnection: 1),
        );

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 100 Continue\r\n\r\n"
                . "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n"
                . "1\r\n!\r\n3\r\nabc\r\n0\r\n\r\n",
        );
    }

    public function rejectsWithoutReadingAnExpectedBody(): void
    {
        $output = new RecordingWriter();
        $input = new ContinueReader($output);

        Connection::serve($input, $output, static fn() => new Response(Status::FORBIDDEN), new Config('/test.sock'));

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }

    public function ignoresContinueInHttp10(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("POST / HTTP/1.0\r\nExpect: 100-continue\r\nContent-Length: 3\r\n\r\nabc");

        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn(Request $request) => new Response(body: $request->body),
            config: new Config('/test.sock'),
        );

        Assert::same(actual: $output->bytes, expected: "HTTP/1.0 200 OK\r\nContent-Length: 3\r\n\r\nabc");
    }

    public function advertisesClosureWhenReturningAnEmptyResponseWithAnUnreadBody(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\nabc");

        Connection::serve($input, $output, static fn() => new Response(), new Config('/test.sock'));

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }

    public function rejectsAnUnsupportedExpectationWithoutCallingTheHandler(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("POST / HTTP/1.1\r\nHost: local\r\nExpect: custom\r\nContent-Length: 3\r\n\r\n");

        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn() => throw new \LogicException('Unexpected request.'),
            config: new Config('/test.sock'),
        );

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 417 Expectation Failed\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }

    public function rejectsATruncatedBodyBeforeTheFinalHead(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\na");

        Connection::serve($input, $output, static function (Request $request) {
            $request->body->read(3);
            $request->body->read(2);

            return new Response();
        }, new Config('/test.sock'));

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }

    public function stopsWritingWhenTheEchoFailsAfterTheFinalHead(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\na");
        Expect::exception(ReadFailed::class);

        try {
            Connection::serve(
                input: $input,
                output: $output,
                handler: static fn(Request $request) => new Response(body: $request->body),
                config: new Config('/test.sock'),
            );
        } finally {
            Assert::same(actual: $output->bytes, expected: "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\na");
        }
    }

    public function reportsAnUnsupportedVersionWithoutCallingTheHandler(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("GET / HTTP/7.1\r\nHost: local\r\n\r\n");

        Connection::serve(
            input: $input,
            output: $output,
            handler: static fn() => throw new \LogicException('Unexpected request.'),
            config: new Config('/test.sock'),
        );

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 505 HTTP Version Not Supported\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }

    public function sendsAnEmpty500AndPropagatesTheHandlerError(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        Expect::exception(\RuntimeException::class)->withMessage('Private details');

        try {
            Connection::serve(
                input: $input,
                output: $output,
                handler: static fn() => throw new \RuntimeException('Private details'),
                config: new Config('/test.sock'),
            );
        } finally {
            Assert::same(
                actual: $output->bytes,
                expected: "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
            );
        }
    }
}
