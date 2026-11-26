<?php

declare(strict_types=1);

use Thesis\Async\Group;
use Thesis\Http\Headers;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\Route;
use Thesis\Http\Server\Router;
use Thesis\Http\Server\TcpEndpoint;
use Thesis\Http\Status;
use Thesis\Net\Certificate;
use Thesis\Net\InetAddress;
use Thesis\Net\ServerTls;
use function Thesis\Async\awaitShutdownSignal;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\exceptionally;
use function Thesis\Http\Server\routeParam;
use function Thesis\Time\ms;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// From the monorepo root, set ARENA to your HttpArena checkout and run:
// docker compose run --rm -p 127.0.0.1:8080:8080 -p 127.0.0.1:8081:8081 \
//   -v "$ARENA/data:/data:ro" -v "$ARENA/certs:/certs:ro" \
//   php php packages/http/examples/http-arena.php
// curl --noproxy localhost -ksS 'https://localhost:8081/json/5?m=3'

/** @var list<array{price: int, quantity: int, ...}> */
$dataset = json_decode(
    json: exceptionally(static fn() => file_get_contents('/data/dataset.json')),
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);

$router = new Router([
    new Route([Method::GET, Method::POST, Method::HEAD], '/baseline11', static function (Request $request) {
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
            body: $request->method === Method::HEAD ? '' : (string) $sum,
        );
    }),
    Route::get('/delay/{ms}', static function (Request $request) {
        $ms = filter_var(routeParam($request, 'ms'), FILTER_VALIDATE_INT);

        if ($ms === false || $ms < 0) {
            return new Response(status: Status::BAD_REQUEST);
        }

        delay(ms($ms));

        return new Response(
            headers: ['Content-Type' => 'text/plain'],
            body: (string) $ms,
        );
    }),
    Route::get('/pipeline', static fn() => new Response(
        headers: ['Content-Type' => 'text/plain'],
        body: 'ok',
    )),
    Route::get('/json/{count}', static function (Request $request, Context $context) use ($dataset) {
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

        $response = Response::json(['items' => $items, 'count' => $count]);

        if ($context->tls !== null) {
            return $response;
        }

        $response = $response->withHeader('Vary', 'Accept-Encoding');
        $encodings = explode(',', strtolower($request->headers->get('accept-encoding') ?? ''));
        if (!in_array('gzip', array_map(trim(...), $encodings), true)) {
            return $response;
        }

        $bytes = $response->takeBody()->readAll();
        $compressed = exceptionally(static fn() => gzencode($bytes));

        return $response
            ->withBody($compressed)
            ->withHeader('Content-Encoding', 'gzip')
        ;
    }),
    Route::post('/echo', static fn(Request $request) => new Response(
        headers: ['Content-Type' => 'application/octet-stream'],
        body: $request->takeBody(),
    )),
    Route::get('/static/{name}', static function (Request $request) {
        $name = routeParam($request, 'name') ?? '';

        if (preg_match('/\A[a-zA-Z0-9_-]+\.[a-z0-9]+\z/', $name) !== 1) {
            return new Response(Status::NOT_FOUND);
        }

        $path = '/data/static/' . $name;

        if (!is_file($path)) {
            return new Response(Status::NOT_FOUND);
        }

        $type = match (pathinfo($name, PATHINFO_EXTENSION)) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'html' => 'text/html',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        $bytes = exceptionally(static fn() => file_get_contents($path));

        return new Response(
            headers: ['Content-Type' => $type],
            body: $bytes,
        );
    }),
]);

$server = new Server(
    config: new Config(endpoints: [
        new TcpEndpoint(
            address: new InetAddress('0.0.0.0', 8080),
            backlog: 4096,
            reusePort: true,
            maxConnections: 32_000,
        ),
        new TcpEndpoint(
            address: new InetAddress('0.0.0.0', 8081),
            backlog: 4096,
            reusePort: true,
            tls: new ServerTls(
                certificate: new Certificate(
                    certFile: '/certs/server.crt',
                    keyFile: '/certs/server.key',
                ),
                alpnProtocols: ['http/1.1'],
            ),
            maxConnections: 4096,
        ),
    ]),
    handler: $router,
);

group(static function (Group $group) use ($server) {
    $server->start($group);

    try {
        awaitShutdownSignal();
    } finally {
        $server->stop();
    }
});
