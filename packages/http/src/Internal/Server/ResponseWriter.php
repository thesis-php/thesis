<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Async\Cancelled;
use Thesis\Http\Body;
use Thesis\Http\Internal\Http1\Framing;
use Thesis\Http\Internal\Http1\RequestHead;
use Thesis\Http\Internal\Http1\ResponseHead;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\ErrorHandler;
use Thesis\Http\Status;
use Thesis\Http\StringBody;
use Thesis\Io\Closer;
use Thesis\Io\ReadFailed;
use Thesis\Io\WriteFailed;
use Thesis\Io\Writer;
use Thesis\Net\Socket;
use Time\Duration;
use function Thesis\Async\timeoutOr;
use function Thesis\Http\Internal\Http1\encodeChunk;
use function Thesis\Http\Internal\Http1\encodeLastChunk;
use function Thesis\Io\close;
use const Thesis\Http\EMPTY_BODY;

/**
 * @internal
 */
final class ResponseWriter implements Writer, Closer
{
    /**
     * @var ?array{ResponseHead, string}
     */
    private ?array $lastResponseHead = null;

    public function __construct(
        private readonly Writer $output,
        private readonly Duration $timeout,
        private readonly ?SocketDeadlines $deadlines,
        private readonly ErrorHandler $errorHandler,
    ) {}

    /**
     * @return array{ResponseHead, string}
     */
    public function encodeHead(Response $response, Body $body, RequestHead $request, bool $isPersistent): array
    {
        $ordinary = $request->method !== Method::HEAD && $request->method !== Method::CONNECT;
        if ($this->lastResponseHead !== null && $ordinary && $body instanceof StringBody) {
            $cached = $this->lastResponseHead;
            $head = $cached[0];
            if ($response->status === $head->status && $body->size === $head->length
                && $request->version === $head->version
                && ($isPersistent && $request->isPersistent) === $head->isPersistent
                && $response->headers->toArray() === $head->headers->toArray() && !$body->mayHaveTrailers) {
                return $cached;
            }
        }

        if ($request->method === Method::HEAD && $response->status >= 200
            && $response->status !== Status::NO_CONTENT && $response->status !== Status::NOT_MODIFIED) {
            $size = $body->size;
            if ($size !== null && $size > 0 && !$response->headers->has('content-length')) {
                $response = $response->withHeader('content-length', (string) $size);
            }
            if ($response->headers->has('trailer')) {
                $response = $response->withoutHeader('trailer');
            }

            $body = EMPTY_BODY;
        }

        $head = ResponseHead::of($response, $request, $body, $isPersistent);
        $result = [$head, $head->toString()];
        if ($ordinary && $head->framing === Framing::Length && \strlen($result[1]) <= 1024) {
            $this->lastResponseHead = $result;
        }

        return $result;
    }

    /**
     * @param int<400, 599> $status
     */
    public function writeError(int $status, ?RequestHead $head = null): void
    {
        if ($head === null) {
            $reply = ResponseHead::forInvalidRequest($status);
        } else {
            $response = new Response($status);
            $reply = ResponseHead::of($response, $head, $response->takeBody(), isPersistent: false);
        }

        $this->write($reply->toString());
    }

    public function writeServerError(RequestHead $head, Request $request): void
    {
        $body = null;

        try {
            try {
                $response = $this->errorHandler->handleError(Status::INTERNAL_SERVER_ERROR, $request);
                $body = $response->takeBody();
                [$reply, $bytes] = $this->encodeHead($response, $body, $head, isPersistent: false);
            } catch (Cancelled $error) {
                throw $error;
            } catch (\Throwable) {
                $this->writeError(Status::INTERNAL_SERVER_ERROR, $head);

                return;
            }

            $this->writeResponse($body, $reply, $bytes);
        } finally {
            $body?->close();
        }
    }

    public function writeResponse(
        Body $body,
        ResponseHead $head,
        string $bytes,
        ?string $first = null,
        bool $bufferedEcho = false,
    ): void {
        $remaining = $head->length;
        if ($remaining !== null && $remaining > 0 && $remaining <= 8192
            && ($first !== null || $bufferedEcho || $body->isBuffered)) {
            try {
                $chunk = $first ?? self::readBody($body, $remaining);
                $first = null;
            } catch (\Throwable $error) {
                $this->write($bytes);

                throw $error;
            }

            $bytes .= $chunk;
            $remaining -= \strlen($chunk);
        }

        $this->write($bytes);
        if ($head->framing === Framing::None) {
            return;
        }

        while ($remaining === null || $remaining > 0) {
            $bytes = $first ?? self::readBody($body, $remaining);
            $first = null;

            if ($bytes === '') {
                break;
            }

            $this->write($head->framing === Framing::Chunked ? encodeChunk($bytes) : $bytes);
            if ($remaining !== null) {
                $remaining -= \strlen($bytes);
            }
        }

        if ($head->framing === Framing::Chunked) {
            $this->write(encodeLastChunk($body->trailers()));
        }
    }

    /**
     * @param ?positive-int $remaining
     */
    public static function readBody(Body $body, ?int $remaining): string
    {
        $max = $body->isBuffered ? 64 * 1024 : 8192;
        $bytes = $body->read(min($max, $remaining ?? $max));
        if ($bytes === null && $remaining !== null) {
            throw new ReadFailed('The response body ended before its declared length.');
        }

        return $bytes ?? '';
    }

    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        if ($this->deadlines !== null && $this->output instanceof Socket) {
            $this->deadlines->write($this->output, $bytes);

            return;
        }

        $written = timeoutOr($this->timeout, function () use ($bytes) {
            $this->output->write($bytes);

            return true;
        });

        if ($written === null) {
            throw new WriteFailed('The response write did not complete in time.');
        }
    }

    public function close(): void
    {
        close($this->output);
    }
}
