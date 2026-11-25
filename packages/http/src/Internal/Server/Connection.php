<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Dispatcher;
use Thesis\Http\Internal\Http1\MalformedMessage;
use Thesis\Http\Internal\Http1\RequestHead;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\DefaultErrorHandler;
use Thesis\Http\Server\ErrorHandler;
use Thesis\Http\Server\Event;
use Thesis\Io\Buffer;
use Thesis\Io\Reader;
use Thesis\Io\Writer;
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

    private bool $isHandlingRequest = false;

    private readonly Buffer $buffer;

    private readonly ResponseWriter $output;

    private readonly BodyInput $bodyInput;

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
    ) {
        $this->buffer = new Buffer();
        $this->requestsRemaining = $config->maxRequestsPerConnection;
        $this->output = new ResponseWriter($output, $config->writeTimeout, $writes, $errorHandler);
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
        $headReader = new HeadReader($this->buffer, $this->input, $this->config, $scheme, $this->idle);
        $canAcceptNextRequest = fn() => $this->requestsRemaining !== 0;
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

            $this->isHandlingRequest = true;

            try {
                $exchange = new Exchange($head, $this->bodyInput, $this->output, $canAcceptNextRequest);
                $canReuse = $exchange->run($handler, $this->requestStarted, $this->requestEnded);
            } finally {
                $this->isHandlingRequest = false;
            }

            if (!$canReuse || !$canAcceptNextRequest()) {
                return;
            }

            $reused = true;
        }
    }

    public function stop(): void
    {
        $this->requestsRemaining = 0;
        if (!$this->isHandlingRequest) {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->requestsRemaining = 0;

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
