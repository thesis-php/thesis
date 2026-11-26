<?php

declare(strict_types=1);

use Thesis\Async\Group;
use Thesis\Dispatcher;
use Thesis\Http\Headers;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Route;
use Thesis\Http\Server\Router;
use Thesis\Http\Server\ServerLogger;
use Thesis\Http\Server\TcpEndpoint;
use Thesis\Logger;
use Thesis\Logger\Formatter\Console;
use Thesis\Net\Certificate;
use Thesis\Net\InetAddress;
use Thesis\Net\ServerTls;
use function Thesis\Async\awaitShutdownSignal;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\timeout;
use function Thesis\exceptionally;
use function Thesis\Http\Server\routeParam;
use function Thesis\Io\stderr;
use function Thesis\Time\parseDuration;
use function Thesis\Time\sec;

if ((@include __DIR__ . '/vendor/autoload.php') === false) {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
}

$index = exceptionally(static fn() => file_get_contents(__DIR__ . '/index.html'));

$logger = new Logger(stderr(), formatter: new Console());
$server = new Server(
    config: new Config(endpoints: [
        new TcpEndpoint(new InetAddress('0.0.0.0', 18080)),
        new TcpEndpoint(
            address: new InetAddress('0.0.0.0', 18443),
            tls: new ServerTls(
                certificate: new Certificate(
                    certFile: __DIR__ . '/tls/localhost.crt',
                    keyFile: __DIR__ . '/tls/localhost.key',
                ),
                alpnProtocols: ['http/1.1'],
            ),
        ),
    ]),
    handler: new Router([
        Route::get('/', static fn() => new Response(
            headers: new Headers(['Content-Type' => 'text/html; charset=utf-8']),
            body: $index,
        )),
        Route::get('/wait/{duration?}', static function (Request $request) {
            $duration = routeParam($request, 'duration') ?? '0s';

            delay(parseDuration($duration));

            return new Response(body: "Waited for {$duration}\n");
        }),
        Route::get('/error', static fn() => throw new Exception('Demo handler failed.')),
    ]),
    dispatcher: new Dispatcher([
        new ServerLogger($logger, accessLog: true),
    ]),
);

group(static function (Group $group) use ($logger, $server) {
    $logger->start($group);

    try {
        $server->start($group);

        awaitShutdownSignal();
    } finally {
        try {
            shield($server->stop(...));
        } finally {
            shield(static fn() => timeout(sec(2), $logger->stop(...)));
        }
    }
});
