<?php

declare(strict_types=1);

use Thesis\Async\Group;
use Thesis\Http\Headers;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Status;
use Thesis\HttpServer\Config;
use Thesis\HttpServer\Server;
use Thesis\HttpServer\TcpEndpoint;
use Thesis\Net\InetAddress;
use function Thesis\Async\awaitSignal;
use function Thesis\Async\group;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$server = new Server(
    config: new Config(
        endpoint: new TcpEndpoint(new InetAddress('0.0.0.0', 8080), backlog: 4096),
        maxConnections: 4096,
    ),
    handler: static function (Request $request) {
        if ($request->uri->getRawPath() !== '/baseline11') {
            return new Response(status: Status::NOT_FOUND);
        }

        parse_str($request->uri->getRawQuery() ?? '', $query);
        $a = $query['a'] ?? null;
        $b = $query['b'] ?? null;
        if (!is_string($a) || !is_string($b) || !is_numeric($a) || !is_numeric($b)) {
            return new Response(status: Status::BAD_REQUEST);
        }

        $sum = (int) $a + (int) $b;
        if ($request->method === Method::POST) {
            $body = $request->takeBody()->readAll();

            if (!is_numeric($body)) {
                return new Response(status: Status::BAD_REQUEST);
            }

            $sum += (int) $body;
        } elseif ($request->method !== Method::GET) {
            return new Response(status: Status::METHOD_NOT_ALLOWED, headers: new Headers(['Allow' => 'GET, POST']));
        }

        return new Response(
            headers: new Headers(['Content-Type' => 'text/plain']),
            body: (string) $sum,
        );
    },
);

group(static function (Group $group) use ($server) {
    $server->run($group);
    awaitSignal(SIGINT, SIGTERM);
    $server->close();
});
