<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\Group;
use function Thesis\Async\group;

/**
 * A self-signed certificate that is its own CA, valid for $name, localhost and 127.0.0.1.
 * Pass its $certFile as the CA file of the other side. Generated once per process.
 *
 * @param non-empty-string $name
 */
function certificate(string $name): Certificate
{
    /** @var array<string, Certificate> $certificates */
    static $certificates = [];

    if (isset($certificates[$name])) {
        return $certificates[$name];
    }

    $dir = sys_get_temp_dir() . '/thesis-net-' . getmypid();

    if (!is_dir($dir)) {
        mkdir($dir);
        register_shutdown_function(static function () use ($dir) {
            $files = glob($dir . '/*');
            array_map(unlink(...), $files === false ? [] : $files);
            rmdir($dir);
        });
    }

    $config = "{$dir}/{$name}.cnf";
    file_put_contents(
        filename: $config,
        data: "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nbasicConstraints = CA:TRUE\n"
        . "subjectAltName = DNS:{$name}, DNS:localhost, IP:127.0.0.1\n",
    );
    $options = [
        'config' => $config,
        'digest_alg' => 'sha256',
        'x509_extensions' => 'ext',
    ];
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    \assert($key instanceof \OpenSSLAsymmetricKey);
    // openssl_csr_new() takes the key by reference
    $request = openssl_csr_new(['commonName' => $name], $key, $options);
    \assert($key instanceof \OpenSSLAsymmetricKey);
    \assert($request instanceof \OpenSSLCertificateSigningRequest);
    $certificate = openssl_csr_sign(
        csr: $request,
        ca_certificate: null,
        private_key: $key,
        days: 1,
        options: $options,
        serial: random_int(1, PHP_INT_MAX),
    );
    \assert($certificate !== false);
    openssl_x509_export_to_file($certificate, "{$dir}/{$name}.crt");
    openssl_pkey_export_to_file($key, "{$dir}/{$name}.key");

    return $certificates[$name] = new Certificate(certFile: "{$dir}/{$name}.crt", keyFile: "{$dir}/{$name}.key");
}

/**
 * Two ends of a TCP connection over loopback: the client one first.
 *
 * @param non-empty-string $host
 * @return array{Socket, Socket}
 */
function tcpPair(string $host = '127.0.0.1'): array
{
    $server = listen($host, 0);

    try {
        return group(static function (Group $group) use ($server) {
            \assert($server->address instanceof InetAddress);
            $accepted = $group->spawn($server->accept(...));
            $client = connect($server->address->ip, $server->address->port);
            $accepted = $accepted->await();
            \assert($accepted !== null);

            return [$client, $accepted];
        });
    } finally {
        $server->close();
    }
}

/**
 * @param positive-int $length
 */
function readExactly(Socket $socket, int $length): string
{
    $bytes = '';

    while (($missing = $length - \strlen($bytes)) > 0) {
        $bytes .= $socket->read($missing);
    }

    return $bytes;
}

/**
 * Sends $up from the client to the server and $down back, all at once.
 *
 * @return array{string, string} what the server and the client have received
 */
function exchange(Socket $client, Socket $server, string $up, string $down): array
{
    return group(static function (Group $group) use ($client, $server, $up, $down) {
        $group->spawn(static fn() => $client->write($up));
        $group->spawn(static fn() => $server->write($down));
        $receivedUp = $group->spawn(static fn() => $up === '' ? '' : readExactly($server, \strlen($up)));
        $receivedDown = $group->spawn(static fn() => $down === '' ? '' : readExactly($client, \strlen($down)));

        return [$receivedUp->await(), $receivedDown->await()];
    });
}
