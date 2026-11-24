<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\HttpClient\Internal\Http1Transport;
use Thesis\HttpClient\Internal\Pipeline;
use Thesis\Net\ClientTls;
use Thesis\Net\Socket;
use Uri\Rfc3986\Uri;

/**
 * @api
 */
final readonly class Client
{
    /**
     * @var \Closure(Request): Response
     */
    private \Closure $dispatch;

    /**
     * @param positive-int $maxConnectionsPerOrigin
     * @param non-negative-int $maxIdleConnections
     * @param list<Middleware> $middleware outermost first
     * @param null|callable(non-empty-string, int<0, 65535>, ?ClientTls): Socket $connector opens a socket to the origin
     * @param positive-int $maxResponseHeadBytes combined limit for informational heads and the final head per exchange
     */
    public function __construct(
        ClientTls $tls = new ClientTls(),
        int $maxConnectionsPerOrigin = 10,
        int $maxIdleConnections = 32,
        array $middleware = [new FollowRedirects(), new DecodeContent()],
        ?callable $connector = null,
        int $maxResponseHeadBytes = 64 * 1024,
    ) {
        $transport = new Http1Transport(
            tls: $tls,
            maxConnectionsPerOrigin: $maxConnectionsPerOrigin,
            maxIdleConnections: $maxIdleConnections,
            connector: $connector,
            maxResponseHeadBytes: $maxResponseHeadBytes,
        );
        $this->dispatch = Pipeline::wrap($transport->send(...), $middleware);
    }

    /**
     * Sends a GET for a URL string. Returns when the final response head arrives.
     */
    public function send(Request|string $request): Response
    {
        return ($this->dispatch)(\is_string($request) ? new Request('GET', new Uri($request)) : $request);
    }

    /**
     * Closes the response body when the callback returns or throws.
     *
     * @template T
     * @param callable(Response): T $consume
     * @return T
     * @throws \Throwable if the callback throws
     */
    public function withResponse(Request|string $request, callable $consume): mixed
    {
        $response = $this->send($request);

        try {
            return $consume($response);
        } finally {
            $response->body->close();
        }
    }
}
