<?php

declare(strict_types=1);

use Thesis\Async\Group;
use Thesis\Http\Headers;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Route;
use Thesis\Http\Server\Router;
use Thesis\Http\Server\TcpEndpoint;
use Thesis\Http\Status;
use Thesis\Net\InetAddress;
use function Thesis\Async\awaitShutdownSignal;
use function Thesis\Async\group;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$baseline = static function (Request $request) {
    $a = filter_var($request->query->get('a'), FILTER_VALIDATE_INT);
    $b = filter_var($request->query->get('b'), FILTER_VALIDATE_INT);

    if ($a === false || $b === false) {
        return new Response(status: Status::BAD_REQUEST);
    }

    $sum = $a + $b;

    if ($request->method === Method::POST) {
        $c = filter_var($request->takeBody()->readAll(), FILTER_VALIDATE_INT);

        if ($c === false) {
            return new Response(status: Status::BAD_REQUEST);
        }

        $sum += $c;
    }

    return new Response(
        headers: new Headers(['Content-Type' => 'text/plain']),
        body: (string) $sum,
    );
};

$server = new Server(
    config: new Config(
        endpoint: new TcpEndpoint(
            address: new InetAddress('0.0.0.0', 8080),
            backlog: 4096,
            reusePort: true,
        ),
        maxConnections: 4096,
    ),
    handler: new Router([
        new Route([Method::GET, Method::POST], '/baseline11', $baseline),
        Route::head('/baseline11', static fn(Request $request) => $baseline($request)->withBody('')),
    ]),
);

group(static function (Group $group) use ($server) {
    $server->start($group);
    awaitShutdownSignal();
    $server->stop();
});
