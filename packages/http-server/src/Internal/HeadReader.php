<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

use Thesis\Http\Http1\MalformedMessage;
use Thesis\Http\Http1\RequestHead;
use Thesis\Http\Status;
use Thesis\HttpServer\Config;
use Thesis\Io\Buffer;
use Thesis\Io\Reader;
use Thesis\Io\UnexpectedEof;
use function Thesis\Async\timeoutOr;

/**
 * @internal
 */
final class HeadReader
{
    private const string END = "\r\n\r\n";

    /**
     * @param 'http'|'https' $scheme the scheme of the connection
     */
    public static function read(
        Buffer $buffer,
        Reader $input,
        Config $config,
        bool $isKeepAlive = false,
        string $scheme = 'http',
    ): ?RequestHead {
        try {
            if ($isKeepAlive && $buffer->count() === 0) {
                // Once a byte arrives, the entire remainder shares one deadline.
                $first = timeoutOr(
                    duration: $config->idleTimeout,
                    body: static fn() => $input->read(min(8192, $config->maxHeadBytes)),
                );

                if ($first === null) {
                    return null;
                }

                $buffer->drain();
                $buffer->write($first);
            }

            $bytes = $buffer->readUntil(self::END);
            if ($bytes !== null) {
                if (\strlen($bytes) + \strlen(self::END) > $config->maxHeadBytes) {
                    throw new \OverflowException();
                }

                return RequestHead::parse($bytes, $scheme);
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

        return RequestHead::parse($bytes, $scheme);
    }
}
