<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Http\Internal\Http1\MalformedMessage;
use Thesis\Http\Internal\Http1\RequestHead;
use Thesis\Http\Server\Config;
use Thesis\Http\Status;
use Thesis\Io\Buffer;
use Thesis\Io\Reader;
use Thesis\Io\UnexpectedEof;
use Thesis\Net\Socket;
use function Thesis\Async\timeoutOr;

/**
 * @internal
 */
final class HeadReader
{
    private const string END = "\r\n\r\n";

    private ?string $lastWire = null;

    private ?RequestHead $lastHead = null;

    /**
     * @var positive-int
     */
    private int $readSize = 8192;

    /**
     * @param 'http'|'https' $scheme the scheme of the connection
     */
    public function __construct(
        private readonly string $scheme,
        private readonly ?SocketDeadlines $idle,
    ) {}

    public function read(
        Buffer $buffer,
        Reader $input,
        Config $config,
        bool $isKeepAlive = false,
    ): ?RequestHead {
        try {
            if ($isKeepAlive && $buffer->count() === 0) {
                // Once a byte arrives, the entire remainder shares one deadline.
                $max = min($this->readSize, $config->maxHeadBytes);
                $first = $this->idle !== null && $input instanceof Socket
                    ? $this->idle->read($input, $max)
                    : timeoutOr($config->idleTimeout, static fn() => $input->read($max));

                if ($first === null) {
                    return null;
                }

                // A short exchange needs little read space. Grow again if the peer fills it.
                $this->readSize = \strlen($first) < 2048 ? 2048 : 8192;
                $buffer->drain();
                // The delimiter is part of the match. Everything after it belongs to the body or the next request.
                if ($this->lastWire !== null && $this->lastHead !== null && str_starts_with($first, $this->lastWire)) {
                    $buffer->write(substr($first, \strlen($this->lastWire)));

                    return $this->lastHead;
                }

                $buffer->write($first);
            }

            $bytes = $buffer->readUntil(self::END);
            if ($bytes !== null) {
                if (\strlen($bytes) + \strlen(self::END) > $config->maxHeadBytes) {
                    throw new \OverflowException();
                }

                return $this->parse($bytes);
            }

            $filled = timeoutOr($config->headTimeout, static function () use ($buffer, $input, $config) {
                $buffer->fillUntil($input, self::END, $config->maxHeadBytes);

                return true;
            });

            if ($filled === null) {
                throw new MalformedMessage('The request head did not arrive in time.', Status::REQUEST_TIMEOUT);
            }
        } catch (UnexpectedEof) {
            if ($buffer->count() === 0) {
                return null;
            }

            throw new MalformedMessage('The connection ended in a request head.');
        } catch (\OverflowException) {
            throw new MalformedMessage(
                message: \sprintf('The request head exceeds %d bytes.', $config->maxHeadBytes),
                status: Status::REQUEST_HEADER_FIELDS_TOO_LARGE,
            );
        }

        $bytes = $buffer->readUntil(self::END) ?? throw new \LogicException('The request head is missing.');

        return $this->parse($bytes);
    }

    private function parse(string $bytes): RequestHead
    {
        if (\strlen($bytes) > 1024) {
            $this->lastWire = null;
            $this->lastHead = null;

            return RequestHead::parse($bytes, $this->scheme);
        }

        $wire = $bytes . self::END;
        if ($wire === $this->lastWire && $this->lastHead !== null) {
            return $this->lastHead;
        }

        $head = RequestHead::parse($bytes, $this->scheme);
        $this->lastWire = $wire;
        $this->lastHead = $head;

        return $head;
    }
}
