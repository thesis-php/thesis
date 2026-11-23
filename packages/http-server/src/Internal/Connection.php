<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

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
use Thesis\HttpServer\Config;
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
 * @internal
 */
final class Connection
{
    /**
     * Serve HTTP/1 requests on one connection. The caller closes the transport in a finally block.
     * A handler failure before the final head gets an empty 500 response and still propagates to the caller.
     *
     * @param callable(Request): Response $handler
     * @param ?ConnectionState $state used by the listener to stop after the current exchange
     */
    public static function serve(
        Reader $input,
        Writer $output,
        callable $handler,
        Config $config,
        ?ConnectionState $state = null,
    ): void {
        $headReader = new HeadReader($state->scheme ?? 'http', $state?->idle);
        $buffer = new Buffer();
        $output = new ResponseWriter($output, $config->writeTimeout, $state?->writes);
        $bodyInput = new BodyInput($input, $config->bodyIdleTimeout);
        $requestsRemaining = $config->maxRequestsPerConnection;
        $reused = false;

        while (true) {
            if (isCancelled()) {
                checkpoint();
            }

            if ($state?->isStopping === true) {
                return;
            }

            try {
                $head = $headReader->read(
                    buffer: $buffer,
                    input: $input,
                    config: $config,
                    isKeepAlive: $reused,
                );
            } catch (MalformedMessage $error) {
                self::writeError($output, $error->status);

                return;
            }

            if ($head === null || $state?->beginRequest() === false) {
                return;
            }

            if ($requestsRemaining !== null) {
                --$requestsRemaining;
            }

            try {
                $keepAlive = self::exchange(
                    head: $head,
                    buffer: $buffer,
                    input: $bodyInput,
                    output: $output,
                    handler: $handler,
                    maxBodyBytes: $config->maxBodyBytes,
                    keepAlive: $requestsRemaining !== 0,
                    state: $state,
                );
            } finally {
                $state?->endRequest();
            }

            if (!$keepAlive) {
                return;
            }

            $reused = true;
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
        if ($head->length === 0) {
            $body = null;
            $bodyReader = null;
            $reader = new StringReader('');
        } else {
            $body = $head->length === null
                ? new ChunkedReader($buffer, $input)
                : new FixedLengthReader($buffer, $input, $head->length);
            if ($body instanceof FixedLengthReader && $continue === null && $buffer->count() >= $body->size) {
                // Its length passed the limit above and the complete body can be read without touching the transport.
                $bodyReader = null;
                $reader = $body;
            } else {
                $bodyReader = new BodyReader($body, $maxBodyBytes, $continue);
                $reader = $body instanceof FixedLengthReader
                    ? new SizedBodyReader($bodyReader, $body->size)
                    : $bodyReader;
            }
        }

        /** @var Headers $emptyTrailers */
        static $emptyTrailers = new Headers();
        $request = new Request(
            method: $head->method,
            uri: $head->uri,
            headers: $head->headers,
            body: $reader,
            trailers: $body instanceof ChunkedReader ? static fn() => $body->trailers : $emptyTrailers,
        );

        try {
            if (isCancelled()) {
                checkpoint();
            }

            $response = $handler($request);
            if ($continue?->failure !== null) {
                throw $continue->failure;
            }

            if ($bodyReader?->failure !== null) {
                throw $bodyReader->failure;
            }

            if ($response->status < 200 || ($request->method === Method::CONNECT && $response->status < 300)) {
                throw new \ValueError('The handler must return a final response without a protocol switch.');
            }

            [$reply, $bytes] = self::encodeHead(
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
                [$reply, $bytes] = self::encodeHead($response, $head, isPersistent: false);
            }

        } catch (Cancelled $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($continue?->failure !== null) {
                throw $continue->failure;
            }

            $failure = $bodyReader?->failure;
            if ($failure instanceof MalformedMessage) {
                self::writeError($output, $failure->status, $head);

                return false;
            }

            if ($failure instanceof ReadFailed && $failure->getPrevious() instanceof UnexpectedEof) {
                self::writeError($output, Status::BAD_REQUEST, $head);

                return false;
            }

            if ($failure !== null) {
                throw $failure;
            }

            try {
                self::writeError($output, Status::INTERNAL_SERVER_ERROR, $head);
            } catch (IoException) {
                // Keep the handler failure available to the caller even if the peer cannot receive the error.
            }

            throw $error;
        }

        $continue?->commit();
        self::writeResponse(
            response: $response,
            head: $reply,
            bytes: $bytes,
            output: $output,
            first: $first,
            bufferedEcho: $response->body === $reader && $body instanceof FixedLengthReader
                && $buffer->count() >= $body->remaining,
        );

        return $reply->isPersistent && self::isBodyComplete($body);
    }

    /**
     * @return array{ResponseHead, string}
     */
    private static function encodeHead(Response $response, RequestHead $request, bool $isPersistent): array
    {
        /** @var ?array{ResponseHead, string} $last */
        static $last = null;
        $ordinary = $request->method !== Method::HEAD && $request->method !== Method::CONNECT;
        if ($last !== null && $ordinary && $response->body instanceof StringReader) {
            $cached = $last;
            $head = $cached[0];
            if ($response->status === $head->status && $response->body->size === $head->length
                && $request->version === $head->version && ($isPersistent && $request->isPersistent) === $head->isPersistent
                && $response->headers->toArray() === $head->headers->toArray()) {
                $trailers = $response->trailers;
                if ($trailers?->toArray() === []) {
                    return $cached;
                }

                // A trailer provider is evaluated once when framing the response.
                $response = clone($response, ['trailers' => $trailers]);
            }
        }

        $head = ResponseHead::of($response, $request, $isPersistent);
        $result = [$head, $head->toString()];
        if ($ordinary && $head->framing === Framing::Length && \strlen($result[1]) <= 1024) {
            $last = $result;
        }

        return $result;
    }

    private static function isBodyComplete(null|ChunkedReader|FixedLengthReader $body): bool
    {
        return match (true) {
            $body === null => true,
            $body instanceof ChunkedReader => $body->trailers !== null,
            $body instanceof FixedLengthReader => $body->remaining === 0,
        };
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

    private static function writeResponse(
        Response $response,
        ResponseHead $head,
        string $bytes,
        Writer $output,
        ?string $first,
        bool $bufferedEcho,
    ): void {
        $remaining = $head->length;
        if ($remaining !== null && $remaining > 0 && $remaining <= 8192
            && ($first !== null || $bufferedEcho || $response->body instanceof StringReader)) {
            try {
                $chunk = $first ?? self::readBody($response->body, $remaining);
                $first = null;
            } catch (\Throwable $error) {
                $output->write($bytes);

                throw $error;
            }

            $bytes .= $chunk;
            $remaining -= \strlen($chunk);
        }

        $output->write($bytes);
        if ($head->framing === Framing::None) {
            return;
        }

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
            $max = $reader instanceof StringReader ? 64 * 1024 : 8192;

            return $reader->read(min($max, $remaining ?? $max));
        } catch (UnexpectedEof $eof) {
            if ($remaining !== null) {
                throw new ReadFailed('The response body ended before its declared length.', previous: $eof);
            }

            return '';
        }
    }
}
