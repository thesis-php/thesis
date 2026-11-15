<?php

declare(strict_types=1);

namespace Thesis\Net\Internal;

use Thesis\Async\Channel;
use Thesis\Async\Group;
use Thesis\Async\StreamWatcher;
use Thesis\Net\ConnectFailed;
use Thesis\Net\Socket;
use function Thesis\Async\group;
use function Thesis\Async\timeoutOr;
use function Thesis\Time\ns;

/**
 * @internal
 */
final readonly class Connector
{
    /**
     * How long an attempt has before the next address is tried alongside, as RFC 8305 (Happy Eyeballs) recommends.
     * In nanoseconds.
     */
    private const int ATTEMPT_DELAY = 250_000_000;

    /**
     * @param non-empty-list<non-empty-string> $ips
     * @param int<0, 65535> $port
     * @param non-empty-string $host what the caller asked for, for the messages and the TLS peer name
     * @throws ConnectFailed
     */
    public static function tcp(array $ips, int $port, bool $noDelay, string $host): Socket
    {
        $context = ['socket' => ['tcp_nodelay' => $noDelay]];
        $attempt = static fn(string $ip) => self::attempt(
            uri: str_contains($ip, ':') ? "tcp://[{$ip}]:{$port}" : "tcp://{$ip}:{$port}",
            context: $context,
            host: $host,
        );
        $outcome = \count($ips) === 1 ? $attempt($ips[0]) : self::race(self::interleave($ips), $attempt);

        if ($outcome instanceof Socket) {
            return $outcome;
        }

        throw new ConnectFailed(\sprintf('Failed to connect to %s:%d: %s', $host, $port, $outcome));
    }

    /**
     * @param non-empty-string $path
     * @throws ConnectFailed
     */
    public static function unix(string $path): Socket
    {
        $outcome = self::attempt("unix://{$path}", context: [], host: null);

        if ($outcome instanceof Socket) {
            return $outcome;
        }

        throw new ConnectFailed(\sprintf('Failed to connect to unix:%s: %s', $path, $outcome));
    }

    /**
     * Starts the next attempt once the previous one fails or after {@see ATTEMPT_DELAY}, the first to connect wins.
     *
     * @param non-empty-list<non-empty-string> $ips
     * @param \Closure(non-empty-string): (Socket|non-empty-string) $attempt
     * @return Socket|non-empty-string
     */
    private static function race(array $ips, \Closure $attempt): Socket|string
    {
        /** @var Channel<Socket|non-empty-string> $outcomes room for every attempt, so that none waits to report */
        $outcomes = new Channel(\count($ips));

        try {
            return group(static function (Group $group) use ($ips, $attempt, $outcomes) {
                $failures = [];
                $running = 0;
                $last = array_key_last($ips);

                foreach ($ips as $i => $ip) {
                    $group->spawn(static function () use ($attempt, $ip, $outcomes) {
                        $outcomes->send($attempt($ip));
                    });
                    ++$running;

                    while ($running > 0) {
                        $outcome = $i === $last
                            ? $outcomes->receive()
                            : timeoutOr(ns(self::ATTEMPT_DELAY), $outcomes->receive(...));

                        if ($outcome === null) {
                            break;
                        }

                        --$running;

                        if ($outcome instanceof Socket) {
                            $group->cancelTasks();

                            return $outcome;
                        }

                        $failures[] = $outcome;

                        if ($i !== $last) {
                            break;
                        }
                    }
                }

                $reason = implode('; ', $failures);
                \assert($reason !== '');

                return $reason;
            });
        } finally {
            // Attempts that connected in the same tick as the winner.
            while (($outcome = $outcomes->tryReceive()) !== null) {
                if ($outcome instanceof Socket) {
                    $outcome->close();
                }
            }
        }
    }

    /**
     * Alternates the address families, starting with the preferred one, so that a broken family
     * costs one {@see ATTEMPT_DELAY}, not one per address.
     *
     * @param non-empty-list<non-empty-string> $ips in the order of preference
     * @return non-empty-list<non-empty-string>
     */
    private static function interleave(array $ips): array
    {
        $first = str_contains($ips[0], ':');
        $preferred = [];
        $other = [];

        foreach ($ips as $ip) {
            if (str_contains($ip, ':') === $first) {
                $preferred[] = $ip;
            } else {
                $other[] = $ip;
            }
        }

        $interleaved = [];

        foreach ($preferred as $i => $ip) {
            $interleaved[] = $ip;

            if (isset($other[$i])) {
                $interleaved[] = $other[$i];
            }
        }

        $interleaved = [...$interleaved, ...\array_slice($other, \count($preferred))];
        \assert($interleaved !== []);

        return $interleaved;
    }

    /**
     * @param non-empty-string $uri
     * @param array<string, array<string, mixed>> $context
     * @param ?non-empty-string $host
     * @return Socket|non-empty-string the reason of a failure
     */
    private static function attempt(string $uri, array $context, ?string $host): Socket|string
    {
        $address = explode('://', $uri, 2)[1] ?? $uri;
        error_clear_last();
        // A context of its own: enableTls() sets options on it, and the default context is shared by every stream.
        $stream = @stream_socket_client(
            address: $uri,
            error_code: $errno,
            error_message: $error,
            timeout: null,
            flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            context: stream_context_create($context),
        );

        if ($stream === false) {
            return \sprintf(
                '%s: %s',
                $address,
                \is_string($error) && $error !== '' ? $error : lastError('Connection failed'),
            );
        }

        try {
            new StreamWatcher($stream)->writable();
        } catch (\Throwable $exception) {
            fclose($stream);

            throw $exception;
        }

        if (stream_socket_get_name($stream, true) === false) {
            $reason = self::socketError($stream);
            fclose($stream);

            return \sprintf('%s: %s', $address, $reason);
        }

        return Socket::create($stream, unix: str_starts_with($uri, 'unix:'), host: $host);
    }

    /**
     * @param resource $stream
     * @return non-empty-string
     */
    private static function socketError(mixed $stream): string
    {
        if (\function_exists('socket_import_stream')) {
            $socket = @socket_import_stream($stream);
            $errno = $socket === false ? 0 : socket_get_option($socket, SOL_SOCKET, SO_ERROR);

            $reason = \is_int($errno) && $errno !== 0 ? socket_strerror($errno) : '';

            if ($reason !== '') {
                return $reason;
            }
        }

        return 'Connection refused or the host is unreachable';
    }
}
