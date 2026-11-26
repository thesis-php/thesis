<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal;

use Thesis\Async\Cancelled;
use Thesis\Async\Instant;
use Thesis\Dispatcher;
use Thesis\Http\Body;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\Event;
use Thesis\Http\Server\Internal\Http1\ChunkedReader;
use Thesis\Http\Server\Internal\Http1\FixedLengthReader;
use Thesis\Http\Server\Internal\Http1\Framing;
use Thesis\Http\Server\Internal\Http1\MalformedMessage;
use Thesis\Http\Server\Internal\Http1\RequestHead;
use Thesis\Http\Server\Internal\Http1\Version;
use Thesis\Http\Status;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use function Thesis\Async\checkpoint;
use function Thesis\Async\isCancelled;
use const Thesis\Http\EMPTY_BODY;

/**
 * @internal
 * @phpstan-import-type Handler from \Thesis\Http\Server
 */
final class Exchange
{
    private null|ChunkedReader|FixedLengthReader $decoder = null;

    private ?BodyReader $bodyReader = null;

    private ?ContinueResponse $continue = null;

    private ?int $status = null;

    private bool $responseWritten = false;

    public bool $isBodyComplete {
        get => match (true) {
            $this->decoder === null => true,
            $this->decoder instanceof ChunkedReader => $this->decoder->isComplete,
            $this->decoder instanceof FixedLengthReader => $this->decoder->remaining === 0,
        };
    }

    public function __construct(
        private readonly RequestHead $head,
        private readonly BodyInput $input,
        private readonly ResponseWriter $output,
        private readonly Connection $connection,
    ) {}

    /**
     * @param Handler $handler
     */
    public function run(
        callable $handler,
        Context $context,
        ?Dispatcher $requestStarted,
        ?Dispatcher $requestEnded,
    ): bool {
        $expect = $this->head->headers->get('expect');
        $rejection = match (true) {
            $this->head->length !== null && $this->head->length > $this->input->maxBodyBytes => Status::CONTENT_TOO_LARGE,
            $expect !== null && strcasecmp($expect, '100-continue') !== 0 => Status::EXPECTATION_FAILED,
            default => null,
        };
        $request = $rejection === null
            ? $this->createRequest($expect)
            : new Request($this->head->method, $this->head->uri, $this->head->headers);
        if ($requestEnded !== null) {
            $startedAt = Instant::now();
        }

        try {
            $requestStarted?->dispatch(new Event\RequestStarted($request));

            if ($rejection !== null) {
                $this->writeError($rejection);

                return false;
            }

            return $this->handle($handler, $request, $context);
        } finally {
            if ($requestEnded !== null) {
                $requestEnded->dispatch(new Event\RequestEnded(
                    request: $request,
                    status: $this->status,
                    responseWritten: $this->responseWritten,
                    duration: Instant::now()->since($startedAt),
                ));
            }
        }
    }

    /**
     * @param Handler $handler
     */
    private function handle(callable $handler, Request $request, Context $context): bool
    {
        $body = null;

        try {
            if (isCancelled()) {
                checkpoint();
            }

            $response = $handler($request, $context);
            $body = $response->takeBody();

            return $this->sendResponse($response, $body);
        } catch (Cancelled $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->handleFailure($error, $request);

            return false;
        } finally {
            $body?->close();
        }
    }

    /**
     * @param \Closure(): void $notify
     */
    public function onBodyComplete(\Closure $notify): void
    {
        if ($this->decoder !== null) {
            $this->decoder->onComplete = $notify;
        }
    }

    private function createRequest(?string $expect): Request
    {
        $this->continue = $this->head->version === Version::Http11 && $expect !== null && $this->head->length !== 0
            ? new ContinueResponse($this->output)
            : null;
        $this->decoder = $this->input->decode($this->head->length);
        if ($this->decoder instanceof ChunkedReader) {
            $this->bodyReader = new BodyReader($this->decoder, $this->continue, remaining: $this->input->maxBodyBytes);
        } elseif ($this->decoder !== null && ($this->continue !== null || !$this->decoder->isFullyBuffered)) {
            $this->bodyReader = new BodyReader($this->decoder, $this->continue);
        }

        return new Request(
            method: $this->head->method,
            uri: $this->head->uri,
            headers: $this->head->headers,
            body: $this->bodyReader ?? $this->decoder ?? EMPTY_BODY,
        );
    }

    private function sendResponse(Response $response, Body $body): bool
    {
        if ($this->continue?->failure !== null) {
            throw $this->continue->failure;
        }

        if ($this->bodyReader?->failure !== null) {
            throw $this->bodyReader->failure;
        }

        if ($response->status < 200 || ($this->head->method === Method::CONNECT && $response->status < 300)) {
            throw new \ValueError('The handler must return a final response without a protocol switch.');
        }

        [$reply, $bytes] = $this->output->encodeHead(
            response: $response,
            body: $body,
            request: $this->head,
            isPersistent: $this->connection->canAcceptNextRequest,
        );
        $first = null;
        $isEcho = $body === ($this->bodyReader ?? $this->decoder);

        if ($this->continue !== null && $this->continue->isPending && $reply->length !== 0) {
            if ($isEcho) {
                // The client may abandon the body on seeing a final head before it has sent any bytes.
                $first = ResponseWriter::readBody($body, $reply->length);
            } elseif ($response->status < Status::BAD_REQUEST && !$body->isBuffered) {
                // A streaming response may read from the request only after producing response bytes.
                $this->continue->send();
            }
        }

        $ignoresBody = $reply->length === 0 || $body->isBuffered;
        $mustClose = $this->continue?->isPending === true || !$this->connection->canAcceptNextRequest
            || ($ignoresBody && !$this->isBodyComplete);

        $reframe = $reply->isPersistent && $mustClose;
        if ($reframe) {
            [$reply, $bytes] = $this->output->encodeHead($response, $body, $this->head, isPersistent: false);
        }

        // The clock or a body property can suspend while the replacement head is encoded.
        $buffered = $reply->framing === Framing::Chunked
            ? !$reframe && $ignoresBody
            : $isEcho && $this->decoder instanceof FixedLengthReader && $this->decoder->isFullyBuffered;
        $this->continue?->commit();
        $this->status = $response->status;
        $this->output->writeResponse(
            body: $body,
            head: $reply,
            bytes: $bytes,
            first: $first,
            buffered: $buffered,
        );

        $this->responseWritten = true;

        return $reply->isPersistent && $this->isBodyComplete;
    }

    private function handleFailure(\Throwable $error, Request $request): void
    {
        if ($this->status !== null) {
            throw $error;
        }

        if ($this->continue?->failure !== null) {
            throw $this->continue->failure;
        }

        $failure = $this->bodyReader?->failure;
        if ($failure instanceof MalformedMessage) {
            $this->writeError($failure->status);

            return;
        }

        if ($failure instanceof ReadFailed && $failure->getPrevious() instanceof UnexpectedEof) {
            $this->writeError(Status::BAD_REQUEST);

            return;
        }

        if ($failure !== null) {
            throw $failure;
        }

        $this->continue?->commit();

        try {
            $this->status = Status::INTERNAL_SERVER_ERROR;
            $this->output->writeServerError($this->head, $request);
            $this->responseWritten = true;
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (\Throwable) {
            // Keep the handler failure available to the caller even if the peer cannot receive the error.
        }

        throw $error;
    }

    /**
     * @param int<400, 599> $status
     */
    private function writeError(int $status): void
    {
        $this->status = $status;
        $this->output->writeError($status, $this->head);
        $this->responseWritten = true;
    }
}
