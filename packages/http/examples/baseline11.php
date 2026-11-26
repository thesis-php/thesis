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
    $a = $request->query->get('a');
    $b = $request->query->get('b');

    if (!is_numeric($a) || !is_numeric($b)) {
        return new Response(status: Status::BAD_REQUEST);
    }

    $sum = (int) $a + (int) $b;

    if ($request->method === Method::POST) {
        $body = $request->takeBody()->readAll();

        if (!is_numeric($body)) {
            return new Response(status: Status::BAD_REQUEST);
        }

        $sum += (int) $body;
    }

    return new Response(
        headers: new Headers(['Content-Type' => 'text/plain']),
        body: (string) $sum,
    );
};

$server = new Server(
    config: new Config(
        endpoint: new TcpEndpoint(new InetAddress('0.0.0.0', 8080), backlog: 4096, reusePort: true),
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
