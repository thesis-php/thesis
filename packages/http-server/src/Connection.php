<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

use Thesis\Async\Cancelled;
use Thesis\Http\Headers;
use Thesis\Http\Http1\ChunkedReader;
use Thesis\Http\Http1\FixedLengthReader;
use Thesis\Http\Http1\Framing;
use Thesis\Http\Http1\MalformedMessage;
use Thesis\Http\Http1\RequestHead;
use Thesis\Http\Http1\ResponseHead;
use Thesis\Http\Http1\Version;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Status;
use Thesis\HttpServer\Internal\BodyInput;
use Thesis\HttpServer\Internal\BodyReader;
use Thesis\HttpServer\Internal\ConnectionState;
use Thesis\HttpServer\Internal\ContinueResponse;
use Thesis\HttpServer\Internal\HeadReader;
use Thesis\HttpServer\Internal\ResponseWriter;
use Thesis\HttpServer\Internal\SizedBodyReader;
use Thesis\Io\Buffer;
use Thesis\Io\IoException;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\StringReader;
use Thesis\Io\UnexpectedEof;
use Thesis\Io\Writer;
use function Thesis\Async\checkpoint;
use function Thesis\Async\isCancelled;
use function Thesis\Http\Http1\encodeChunk;
use function Thesis\Http\Http1\encodeLastChunk;

/**
 * @api
 */
final class Connection
{
    /**
     * Serve one plaintext connection. The caller must close the transport in a finally block.
     * A handler failure before the final head gets an empty 500 response and still propagates to the caller.
     *
     * @param callable(Request): Response $handler
     * @param ?ConnectionState $state used by the listener to stop after the current exchange
     */
    public static function serve(
        Reader $input,
        Writer $output,
        callable $handler,
        Options $options = new Options(),
        ?ConnectionState $state = null,
    ): void {
        $buffer = new Buffer();
        $output = new ResponseWriter($output, $options->writeTimeout);
        $bodyInput = new BodyInput($input, $options->bodyIdleTimeout);

        for ($served = 0; $served < $options->maxRequestsPerConnection; ++$served) {
            if (isCancelled()) {
                checkpoint();
            }

            if ($state?->isStopping === true) {
                return;
            }

            try {
                $head = HeadReader::read(
                    buffer: $buffer,
                    input: $input,
                    options: $options,
                    isKeepAlive: $served > 0,
                    scheme: $state->scheme ?? 'http',
                );
            } catch (MalformedMessage $error) {
                self::writeError($output, $error->status);

                return;
            }

            if ($head === null || $state?->beginRequest() === false) {
                return;
            }

            try {
                $keepAlive = self::exchange(
                    head: $head,
                    buffer: $buffer,
                    input: $bodyInput,
                    output: $output,
                    handler: $handler,
                    maxBodyBytes: $options->maxBodyBytes,
                    keepAlive: $served + 1 < $options->maxRequestsPerConnection,
                    state: $state,
                );
            } finally {
                $state?->endRequest();
            }

            if (!$keepAlive) {
                return;
            }
        }
    }

    /**
     * @param callable(Request): Response $handler
     * @param non-negative-int $maxBodyBytes
     */
    private static function exchange(
        RequestHead $head,
        Buffer $buffer,
        Reader $input,
        Writer $output,
        callable $handler,
        int $maxBodyBytes,
        bool $keepAlive,
        ?ConnectionState $state,
    ): bool {
        if ($head->length !== null && $head->length > $maxBodyBytes) {
            self::writeError($output, Status::CONTENT_TOO_LARGE, $head);

            return false;
        }

        $expect = $head->headers->get('expect');
        if ($expect !== null && strcasecmp($expect, '100-continue') !== 0) {
            self::writeError($output, Status::EXPECTATION_FAILED, $head);

            return false;
        }

        $continue = $head->version === Version::Http11 && $expect !== null && $head->length !== 0
            ? new ContinueResponse($output)
            : null;
        $body = $head->length === null
            ? new ChunkedReader($buffer, $input)
            : new FixedLengthReader($buffer, $input, $head->length);
        $bodyReader = new BodyReader($body, $maxBodyBytes, $continue);
        $reader = $body instanceof FixedLengthReader ? new SizedBodyReader($bodyReader, $body->size) : $bodyReader;
        $request = new Request(
            method: $head->method,
            uri: $head->uri,
            headers: $head->headers,
            body: $reader,
            trailers: $body instanceof ChunkedReader ? static fn() => $body->trailers : new Headers(),
        );

        try {
            if (isCancelled()) {
                checkpoint();
            }

            $response = $handler($request);
            if ($continue?->failure !== null) {
                throw $continue->failure;
            }

            if ($bodyReader->failure !== null) {
                throw $bodyReader->failure;
            }

            if ($response->status < 200 || ($request->method === Method::CONNECT && $response->status < 300)) {
                throw new \ValueError('The handler must return a final response without a protocol switch.');
            }

            $reply = ResponseHead::of(
                response: $response,
                request: $head,
                isPersistent: $keepAlive && $state?->isStopping !== true,
            );
            $first = null;

            if ($continue !== null && $continue->isPending && $reply->length !== 0) {
                if ($response->body === $reader) {
                    // The client may abandon the body on seeing a final head before it has sent any bytes.
                    $first = self::readBody($response->body, $reply->length);
                } elseif ($response->status < Status::BAD_REQUEST && !$response->body instanceof StringReader) {
                    // A streaming response may read from the request only after producing response bytes.
                    $continue->send();
                }
            }

            $ignoresBody = $reply->length === 0 || $response->body instanceof StringReader;
            $mustClose = $continue?->isPending === true || $state?->isStopping === true
                || ($ignoresBody && !self::isBodyComplete($body));

            if ($reply->isPersistent && $mustClose) {
                $reply = ResponseHead::of($response, $head, isPersistent: false);
            }

            $bytes = $reply->toString();
        } catch (Cancelled $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($continue?->failure !== null) {
                throw $continue->failure;
            }

            if ($bodyReader->failure instanceof MalformedMessage) {
                self::writeError($output, $bodyReader->failure->status, $head);

                return false;
            }

            if ($bodyReader->failure instanceof ReadFailed && $bodyReader->failure->getPrevious() instanceof UnexpectedEof) {
                self::writeError($output, Status::BAD_REQUEST, $head);

                return false;
            }

            if ($bodyReader->failure !== null) {
                throw $bodyReader->failure;
            }

            try {
                self::writeError($output, Status::INTERNAL_SERVER_ERROR, $head);
            } catch (IoException) {
                // Keep the handler failure available to the caller even if the peer cannot receive the error.
            }

            throw $error;
        }

        $continue?->commit();
        $output->write($bytes);
        self::writeBody(response: $response, head: $reply, output: $output, first: $first);

        return $reply->isPersistent && self::isBodyComplete($body);
    }

    private static function isBodyComplete(ChunkedReader|FixedLengthReader $body): bool
    {
        return $body instanceof ChunkedReader ? $body->trailers !== null : $body->remaining === 0;
    }

    /**
     * @param int<400, 599> $status
     */
    private static function writeError(Writer $output, int $status, ?RequestHead $head = null): void
    {
        if ($head === null) {
            $reason = Status::reasonPhrase($status) ?? '';
            $output->write("HTTP/1.1 {$status} {$reason}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

            return;
        }

        $response = new Response($status);
        $output->write(ResponseHead::of($response, $head, isPersistent: false)->toString());
    }

    private static function writeBody(Response $response, ResponseHead $head, Writer $output, ?string $first): void
    {
        if ($head->framing === Framing::None) {
            return;
        }

        $remaining = $head->length;

        while ($remaining === null || $remaining > 0) {
            $bytes = $first ?? self::readBody($response->body, $remaining);
            $first = null;

            if ($bytes === '') {
                break;
            }

            $output->write($head->framing === Framing::Chunked ? encodeChunk($bytes) : $bytes);
            if ($remaining !== null) {
                $remaining -= \strlen($bytes);
            }
        }

        if ($head->framing === Framing::Chunked) {
            $trailers = $response->trailers ?? throw new \ValueError('Response trailers are still pending at EOF.');
            $output->write(encodeLastChunk($trailers));
        }
    }

    /**
     * @param ?positive-int $remaining
     */
    private static function readBody(Reader $reader, ?int $remaining): string
    {
        try {
            return $reader->read(min(8192, $remaining ?? 8192));
        } catch (UnexpectedEof $eof) {
            if ($remaining !== null) {
                throw new ReadFailed('The response body ended before its declared length.', previous: $eof);
            }

            return '';
        }
    }
}
