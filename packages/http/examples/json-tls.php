<?php

declare(strict_types=1);

use Thesis\Async\Group;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Route;
use Thesis\Http\Server\Router;
use Thesis\Http\Server\TcpEndpoint;
use Thesis\Http\Status;
use Thesis\Net\Certificate;
use Thesis\Net\InetAddress;
use Thesis\Net\ServerTls;
use function Thesis\Async\awaitShutdownSignal;
use function Thesis\Async\group;
use function Thesis\exceptionally;
use function Thesis\Http\Server\routeParam;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// From the monorepo root, set ARENA to your HttpArena checkout and run:
// docker compose run --rm -p 127.0.0.1:8081:8081 -v "$ARENA/data:/data:ro" -v "$ARENA/certs:/certs:ro" \
//   php php packages/http/examples/json-tls.php
// curl --noproxy localhost -ksS 'https://localhost:8081/json/5?m=3'

/** @var list<array{price: int, quantity: int, ...}> $dataset */
$dataset = json_decode(
    json: exceptionally(static fn() => file_get_contents('/data/dataset.json')),
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);

$json = static function (Request $request) use ($dataset) {
    $count = filter_var(routeParam($request, 'count'), FILTER_VALIDATE_INT);
    $multiplier = filter_var($request->query->get('m'), FILTER_VALIDATE_INT);

    if ($count === false || $count < 1 || $count > count($dataset) || $multiplier === false) {
        return new Response(status: Status::BAD_REQUEST);
    }

    $items = [];
    foreach (array_slice($dataset, 0, $count) as $item) {
        $item['total'] = $item['price'] * $item['quantity'] * $multiplier;
        $items[] = $item;
    }

    return Response::json(['items' => $items, 'count' => $count]);
};

$server = new Server(
    config: new Config(
        endpoint: new TcpEndpoint(
            address: new InetAddress('0.0.0.0', 8081),
            backlog: 4096,
            reusePort: true,
        ),
        tls: new ServerTls(
            certificate: new Certificate(
                certFile: '/certs/server.crt',
                keyFile: '/certs/server.key',
            ),
            alpnProtocols: ['http/1.1'],
        ),
        maxConnections: 4096,
    ),
    handler: new Router([Route::get('/json/{count}', $json)]),
);

group(static function (Group $group) use ($server) {
    $server->start($group);
    awaitShutdownSignal();
    $server->stop();
});
