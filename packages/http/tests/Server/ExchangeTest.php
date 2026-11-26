<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Internal\Connection;
use Thesis\Http\Server\Stub\ContinueReader;
use Thesis\Http\Server\Stub\PrefixedBody;
use Thesis\Http\Server\Stub\RecordingWriter;
use Thesis\Http\Server\Stub\TextErrorHandler;
use Thesis\Http\Status;
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

        $connection = new Connection($input, $output, new Config('/test.sock', maxRequestsPerConnection: 1));
        $connection->run(static function (Request $request) use ($output) {
            Assert::same(actual: $output->bytes, expected: '');

            return new Response(body: $request->takeBody());
        });

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

        $connection = new Connection($input, $output, new Config('/test.sock', maxRequestsPerConnection: 1));
        $connection->run(static fn(Request $request) => new Response(body: new PrefixedBody($request->takeBody())));

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

        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(static fn() => new Response(Status::FORBIDDEN));

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }

    public function ignoresContinueInHttp10(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("POST / HTTP/1.0\r\nExpect: 100-continue\r\nContent-Length: 3\r\n\r\nabc");

        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(static fn(Request $request) => new Response(body: $request->takeBody()));

        Assert::same(actual: $output->bytes, expected: "HTTP/1.0 200 OK\r\nContent-Length: 3\r\n\r\nabc");
    }

    public function advertisesClosureWhenReturningAnEmptyResponseWithAnUnreadBody(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\nabc");

        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(static fn() => new Response());

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }

    public function rejectsAnUnsupportedExpectationWithoutCallingTheHandler(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("POST / HTTP/1.1\r\nHost: local\r\nExpect: custom\r\nContent-Length: 3\r\n\r\n");

        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(static fn() => throw new \LogicException('Unexpected request.'));

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 417 Expectation Failed\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }

    public function rejectsATruncatedBodyBeforeTheFinalHead(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 3\r\n\r\na");

        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(static function (Request $request) {
            $body = $request->takeBody();
            $body->read(3);
            $body->read(2);

            return new Response();
        });

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
            $connection = new Connection($input, $output, new Config('/test.sock'));
            $connection->run(static fn(Request $request) => new Response(body: $request->takeBody()));
        } finally {
            Assert::same(actual: $output->bytes, expected: "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\na");
        }
    }

    public function reportsAnUnsupportedVersionWithoutCallingTheHandler(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("GET / HTTP/7.1\r\nHost: local\r\n\r\n");

        $connection = new Connection($input, $output, new Config('/test.sock'));
        $connection->run(static fn() => throw new \LogicException('Unexpected request.'));

        Assert::same(
            actual: $output->bytes,
            expected: "HTTP/1.1 505 HTTP Version Not Supported\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );
    }

    public function rejectsAResponseWhoseBodyWasAlreadySent(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader(str_repeat("GET / HTTP/1.1\r\nHost: local\r\n\r\n", 2));
        $response = new Response(body: 'ok');
        Expect::exception(\LogicException::class);

        try {
            $connection = new Connection($input, $output, new Config('/test.sock'));
            $connection->run(static fn() => $response->withStatus(Status::OK));
        } finally {
            Assert::same(
                actual: $output->bytes,
                expected: "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok"
                    . self::defaultErrorResponse(),
            );
        }
    }

    public function formatsThe500AndPropagatesTheHandlerError(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        Expect::exception(\RuntimeException::class)->withMessage('Private details');

        try {
            $connection = new Connection($input, $output, new Config('/test.sock'), new TextErrorHandler());
            $connection->run(static fn() => throw new \RuntimeException('Private details'));
        } finally {
            Assert::same(
                actual: $output->bytes,
                expected: "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 7\r\nConnection: close\r\n\r\nGET 500",
            );
        }
    }

    public function sendsAnHtml500AndPropagatesTheHandlerError(): void
    {
        $output = new RecordingWriter();
        $input = new StringReader("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        Expect::exception(\RuntimeException::class)->withMessage('Private details');

        try {
            $connection = new Connection($input, $output, new Config('/test.sock'));
            $connection->run(static fn() => throw new \RuntimeException('Private details'));
        } finally {
            Assert::same(
                actual: $output->bytes,
                expected: self::defaultErrorResponse(),
            );
        }
    }

    private static function defaultErrorResponse(): string
    {
        $body = <<<'HTML'
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>500 Internal Server Error</title>
            </head>
            <body>
                <h1>500 Internal Server Error</h1>
            </body>
            </html>
            HTML;

        return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/html; charset=utf-8\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    }
}
