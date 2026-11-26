<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal;

use Psr\Clock\ClockInterface;
use Thesis\Async\ReadObserver;
use Thesis\Async\Task;
use Thesis\Dispatcher;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\DefaultErrorHandler;
use Thesis\Http\Server\ErrorHandler;
use Thesis\Http\Server\Event;
use Thesis\Http\Server\Internal\Http1\MalformedMessage;
use Thesis\Http\Server\Internal\Http1\RequestHead;
use Thesis\Io\Buffer;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use Thesis\Io\Writer;
use Thesis\Net\Socket;
use Thesis\Net\SocketClosed;
use Thesis\Time\WallClock;
use function Thesis\Async\checkpoint;
use function Thesis\Async\isCancelled;
use function Thesis\Io\close;

/**
 * @internal
 */
final class Connection
{
    /**
     * @var ?non-negative-int null allows unlimited requests
     */
    private ?int $requestsRemaining;

    /**
     * @var ?Task<void> the listener's task serving this connection
     */
    public ?Task $task = null;

    private ?ReadObserver $recheckOnNextRequest = null;

    private ?Exchange $exchange = null;

    private readonly Buffer $buffer;

    private readonly ResponseWriter $output;

    private readonly BodyInput $bodyInput;

    public bool $canAcceptNextRequest {
        get => $this->requestsRemaining !== 0;
    }

    public function __construct(
        private readonly Reader $input,
        Writer $output,
        private readonly Config $config,
        ErrorHandler $errorHandler = new DefaultErrorHandler(),
        private readonly ?SocketDeadlines $idle = null,
        ?SocketDeadlines $writes = null,
        private readonly ?Dispatcher $requestStarted = null,
        private readonly ?Dispatcher $requestEnded = null,
        private readonly Dispatcher $dispatcher = new Dispatcher(),
        ClockInterface $clock = new WallClock(),
    ) {
        $this->buffer = new Buffer();
        $this->requestsRemaining = $config->maxRequestsPerConnection;
        $this->output = new ResponseWriter($output, $config->writeTimeout, $writes, $errorHandler, $clock);
        $this->bodyInput = new BodyInput($this->buffer, $input, $config->bodyIdleTimeout, $config->maxBodyBytes);
    }

    /**
     * Serve HTTP/1 requests on one connection. The caller closes the connection in a finally block.
     * A handler failure before the final head gets a 500 response and still propagates to the caller.
     *
     * @param callable(Request): Response $handler
     * @param 'http'|'https' $scheme
     */
    public function run(callable $handler, string $scheme = 'http'): void
    {
        if ($this->input instanceof Socket && $this->task !== null) {
            $this->input->observeReadHangUp($this->probeDisconnect(...));
        }

        $headReader = new HeadReader($this->buffer, $this->input, $this->config, $scheme, $this->idle);
        $reused = false;

        while (true) {
            if (isCancelled()) {
                checkpoint();
            }

            $head = $this->readHead($headReader, $reused);
            if ($head === null || $this->requestsRemaining === 0) {
                return;
            }

            if ($this->requestsRemaining !== null) {
                --$this->requestsRemaining;
            }

            try {
                $this->exchange = new Exchange($head, $this->bodyInput, $this->output, $this);
                // Only the exchange needs this head. A long head must be released before the next idle wait.
                unset($head);
                if ($this->recheckOnNextRequest !== null) {
                    $this->recheckOnNextRequest->recheck();
                    $this->recheckOnNextRequest = null;
                }
                $canReuse = $this->exchange->run($handler, $this->requestStarted, $this->requestEnded);
            } finally {
                $this->exchange = null;
            }

            if (!$canReuse || $this->requestsRemaining === 0) {
                return;
            }

            $reused = true;
        }
    }

    private function probeDisconnect(ReadObserver $observer): bool
    {
        $exchange = $this->exchange;
        if ($exchange !== null && !$exchange->isBodyComplete) {
            $exchange->onBodyComplete($observer->recheck(...));

            return false;
        }

        if ($exchange !== null && $this->buffer->count() === 0) {
            \assert($this->input instanceof Socket);

            try {
                $bytes = $this->input->tryRead(1);
            } catch (SocketClosed) {
                return false;
            } catch (UnexpectedEof|ReadFailed) {
                $this->task?->cancel();

                return false;
            }

            if ($bytes === null) {
                return true;
            }

            $this->buffer->write($bytes);
        }

        $this->recheckOnNextRequest = $observer;

        return false;
    }

    public function stop(): void
    {
        $this->requestsRemaining = 0;
        if ($this->exchange === null) {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->requestsRemaining = 0;

        $this->task = null;
        $this->recheckOnNextRequest = null;
        close($this->input);
        $this->output->close();
    }

    private function readHead(HeadReader $headReader, bool $isKeepAlive): ?RequestHead
    {
        if ($this->requestsRemaining === 0) {
            return null;
        }

        try {
            return $headReader->read($isKeepAlive);
        } catch (MalformedMessage $error) {
            $this->dispatcher->dispatch(new Event\RequestHeadRejected($error->status, $error->getMessage()));
            $this->output->writeError($error->status);

            return null;
        }
    }
}
