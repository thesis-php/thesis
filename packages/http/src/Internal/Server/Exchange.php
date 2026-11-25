<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Async\Cancelled;
use Thesis\Http\Body;
use Thesis\Http\Internal\Http1\ChunkedReader;
use Thesis\Http\Internal\Http1\FixedLengthReader;
use Thesis\Http\Internal\Http1\MalformedMessage;
use Thesis\Http\Internal\Http1\RequestHead;
use Thesis\Http\Internal\Http1\Version;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Status;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use function Thesis\Async\checkpoint;
use function Thesis\Async\isCancelled;
use const Thesis\Http\EMPTY_BODY;

/**
 * @internal
 */
final class Exchange
{
    private null|ChunkedReader|FixedLengthReader $decoder = null;

    private ?BodyReader $bodyReader = null;

    private ?ContinueResponse $continue = null;

    private bool $committed = false;

    private bool $isBodyComplete {
        get => match (true) {
            $this->decoder === null => true,
            $this->decoder instanceof ChunkedReader => $this->decoder->isComplete,
            $this->decoder instanceof FixedLengthReader => $this->decoder->remaining === 0,
        };
    }

    /**
     * @param \Closure(): bool $canAcceptNextRequest
     */
    public function __construct(
        private readonly RequestHead $head,
        private readonly BodyInput $input,
        private readonly ResponseWriter $output,
        private readonly \Closure $canAcceptNextRequest,
    ) {}

    /**
     * @param callable(Request): Response $handler
     */
    public function run(callable $handler): bool
    {
        if ($this->head->length !== null && $this->head->length > $this->input->maxBodyBytes) {
            $this->output->writeError(Status::CONTENT_TOO_LARGE, $this->head);

            return false;
        }

        $expect = $this->head->headers->get('expect');
        if ($expect !== null && strcasecmp($expect, '100-continue') !== 0) {
            $this->output->writeError(Status::EXPECTATION_FAILED, $this->head);

            return false;
        }

        $request = $this->createRequest($expect);
        $body = null;

        try {
            if (isCancelled()) {
                checkpoint();
            }

            $response = $handler($request);
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
            isPersistent: ($this->canAcceptNextRequest)(),
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
        $mustClose = $this->continue?->isPending === true || !($this->canAcceptNextRequest)()
            || ($ignoresBody && !$this->isBodyComplete);

        if ($reply->isPersistent && $mustClose) {
            [$reply, $bytes] = $this->output->encodeHead($response, $body, $this->head, isPersistent: false);
        }

        $this->continue?->commit();
        $this->committed = true;
        $this->output->writeResponse(
            body: $body,
            head: $reply,
            bytes: $bytes,
            first: $first,
            bufferedEcho: $isEcho && $this->decoder instanceof FixedLengthReader
                && $this->decoder->isFullyBuffered,
        );

        return $reply->isPersistent && $this->isBodyComplete;
    }

    private function handleFailure(\Throwable $error, Request $request): void
    {
        if ($this->committed) {
            throw $error;
        }

        if ($this->continue?->failure !== null) {
            throw $this->continue->failure;
        }

        $failure = $this->bodyReader?->failure;
        if ($failure instanceof MalformedMessage) {
            $this->output->writeError($failure->status, $this->head);

            return;
        }

        if ($failure instanceof ReadFailed && $failure->getPrevious() instanceof UnexpectedEof) {
            $this->output->writeError(Status::BAD_REQUEST, $this->head);

            return;
        }

        if ($failure !== null) {
            throw $failure;
        }

        $this->continue?->commit();

        try {
            $this->output->writeServerError($this->head, $request);
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (\Throwable) {
            // Keep the handler failure available to the caller even if the peer cannot receive the error.
        }

        throw $error;
    }
}
