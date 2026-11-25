<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Psr\Log\LoggerInterface;
use Thesis\Dispatcher\Subscriber;
use Thesis\Net\InetAddress;
use function Thesis\Time\toMsFloat;

/**
 * @api
 */
final readonly class ServerLogger implements Subscriber
{
    public function __construct(
        private LoggerInterface $logger,
        private bool $accessLog = false,
    ) {}

    public function listeners(): iterable
    {
        yield $this->started(...);
        yield $this->stopping(...);
        yield $this->stopped(...);
        yield $this->failed(...);
        yield $this->rejected(...);

        if ($this->accessLog) {
            yield $this->ended(...);
        }
    }

    private function started(Event\ServerStarted $event): void
    {
        $address = $event->address;
        $context = ['address' => (string) $address];
        if (!$address instanceof InetAddress) {
            $this->logger->info('HTTP server listening on {address}', $context);

            return;
        }

        $authority = match ($address->ip) {
            '0.0.0.0' => "127.0.0.1:{$address->port}",
            '::' => "[::1]:{$address->port}",
            default => (string) $address,
        };
        $scheme = $event->tls ? 'https' : 'http';
        $context['url'] = "{$scheme}://{$authority}/";
        $this->logger->info('HTTP server listening on {url}', $context);
    }

    private function stopping(Event\ServerStopping $event): void
    {
        $this->logger->info('Stopping HTTP server on {address}', [
            'address' => (string) $event->address,
            'connections' => $event->connections,
            'timeout_ms' => toMsFloat($event->timeout),
        ]);
    }

    private function stopped(Event\ServerStopped $event): void
    {
        $this->logger->info('HTTP server stopped on {address}', [
            'address' => (string) $event->address,
            'forced_connections' => $event->forcedConnections,
        ]);
    }

    private function failed(Event\ConnectionHandlingFailed|Event\TlsHandshakeFailed $event): void
    {
        $context = [
            'address' => (string) $event->localAddress,
            'peer' => (string) $event->remoteAddress,
            'exception' => $event->error,
        ];

        if ($event instanceof Event\TlsHandshakeFailed) {
            $this->logger->debug('TLS handshake failed for {peer}', $context);
        } else {
            $this->logger->error('HTTP server failed to handle the connection from {peer}', $context);
        }
    }

    private function rejected(Event\RequestHeadRejected $event): void
    {
        $this->logger->debug('HTTP request head rejected', ['status' => $event->status, 'reason' => $event->reason]);
    }

    private function ended(Event\RequestEnded $event): void
    {
        $this->logger->info('{method} {path}', [
            'method' => $event->request->method,
            'path' => $event->request->uri->getRawPath(),
            'status' => $event->status,
            'response_written' => $event->responseWritten,
            'duration_ms' => toMsFloat($event->duration),
        ]);
    }
}
