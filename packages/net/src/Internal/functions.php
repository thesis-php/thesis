<?php

declare(strict_types=1);

namespace Thesis\Net\Internal;

use Thesis\Net\Address;
use Thesis\Net\DnsFailed;
use Thesis\Net\InetAddress;
use Thesis\Net\ListenFailed;
use Thesis\Net\UnixAddress;

/**
 * The message of the error the last suppressed call raised, without the name of the function.
 * Call error_clear_last() before that call.
 *
 * @internal
 * @param non-empty-string $default if the call raised nothing
 * @return non-empty-string
 */
function lastError(string $default): string
{
    $message = error_get_last()['message'] ?? '';
    $message = preg_replace('/^\w+\(\): /', '', $message) ?? $message;

    return $message === '' ? $default : $message;
}

/**
 * A host name in lower case, internationalized labels in their ASCII form (UTS #46): `Пример.test` is
 * `xn--e1afmkfd.test`. That is the form DNS and certificates know.
 *
 * @internal
 * @param non-empty-string $host
 * @return ?non-empty-string null if $host is not a valid internationalized name
 * @throws DnsFailed if the name is internationalized and ext-intl is missing
 */
function asciiHost(string $host): ?string
{
    if (preg_match('/[^\x00-\x7F]/', $host) !== 1) {
        return strtolower($host);
    }

    if (!\function_exists('idn_to_ascii')) {
        throw new DnsFailed(\sprintf('"%s" is an internationalized host name: install ext-intl to resolve it.', $host));
    }

    $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

    return $ascii === false || $ascii === '' ? null : strtolower($ascii);
}

/**
 * @internal
 * @return ?non-empty-string the IP address without brackets, null if $host is not one
 */
function ip(string $host): ?string
{
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);

        return $host !== '' && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $host : null;
    }

    return $host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;
}

/**
 * @internal
 * @param string|false $name of stream_socket_get_name()
 */
function address(string|false $name, bool $unix): Address
{
    if ($unix || $name === false) {
        return new UnixAddress(path: $name === false ? '' : $name);
    }

    $colon = strrpos($name, ':');
    \assert($colon !== false);
    $ip = trim(substr($name, 0, $colon), '[]');
    \assert($ip !== '');
    $port = (int) substr($name, $colon + 1);
    \assert($port >= 0 && $port <= 65535);

    return new InetAddress(ip: $ip, port: $port);
}

/**
 * @internal
 * @param non-empty-string $uri
 * @param array<non-empty-string, mixed> $options of the socket context
 * @return resource
 * @throws ListenFailed
 */
function listen(string $uri, array $options): mixed
{
    error_clear_last();
    // A context of its own: accepted sockets share it, and enableTls() sets options on it.
    $stream = @stream_socket_server(
        address: $uri,
        error_code: $errno,
        error_message: $error,
        flags: STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        context: stream_context_create(['socket' => $options]),
    );

    if ($stream === false) {
        throw new ListenFailed(\sprintf(
            'Failed to listen on %s: %s',
            $uri,
            \is_string($error) && $error !== '' ? $error : lastError('unknown error'),
        ));
    }

    return $stream;
}
