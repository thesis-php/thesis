<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Thesis\Dispatcher;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Stub\CallbackMiddleware;
use Thesis\Http\Server\Stub\TextErrorHandler;
use Thesis\Http\Status;
use Thesis\Net\UnixAddress;
use Uri\Rfc3986\Uri;
use function Thesis\Http\getRequest;

#[Test]
final readonly class RouterTest
{
    private Context $context;

    public function __construct()
    {
        $address = new UnixAddress('');
        $this->context = new Context($address, $address, null, new DefaultErrorHandler(), new Dispatcher());
    }

    public function listsOriginalRoutesInRegistrationOrder(): void
    {
        $user = new Route([Method::GET, Method::POST, Method::PUT], '/users/{id}', self::user(...));
        $current = Route::get('/users/me', self::user(...));
        $router = new Router(['user' => $user, 'current' => $current]);

        Assert::same($router->list, [$user, $current]);
    }

    public function choosesTheMostSpecificCompletePathBeforeTheMethod(): void
    {
        $router = new Router([
            new Route(Method::GET, '/users/{id}', self::user(...)),
            new Route(Method::POST, '/users/me', static fn() => new Response(body: 'me')),
            new Route(
                methods: Method::GET,
                pattern: '/{group}/me/{id}',
                handler: static fn($request) => new Response(body: implode('/', routeMatch($request)->params ?? [])),
            ),
            new Route(Method::GET, '/users/{id}/info', static fn() => new Response(body: 'info')),
        ]);

        $head = new Request(Method::HEAD, new Uri('/users/a+b%20c'));
        Assert::same($router($head, $this->context)->takeBody()->readAll(), 'HEAD a+b c');
        $post = new Request(Method::POST, new Uri('/users/m%65'));
        Assert::same($router($post, $this->context)->takeBody()->readAll(), 'me');
        Assert::same($router(getRequest('/users/me/info'), $this->context)->takeBody()->readAll(), 'info');
        Assert::same($router(getRequest('/users/me/42'), $this->context)->takeBody()->readAll(), 'users/42');

        $response = $router(getRequest('/users/me'), $this->context);
        Assert::same($response->status, Status::METHOD_NOT_ALLOWED);
        Assert::same($response->headers->get('Allow'), Method::POST);
    }

    public function callsTheDefaultHandlerOnlyForUnknownPaths(): void
    {
        $request = getRequest('/style.css');
        $router = new Router(
            routes: [Route::get('/', static fn() => new Response(body: 'home'))],
            defaultHandler: function (Request $received, Context $context) use ($request) {
                Assert::same($received, $request);
                Assert::same($context, $this->context);

                return new Response(body: 'css');
            },
        );

        Assert::same($router->match($request), RouteFailure::NotFound);
        Assert::same($router($request, $this->context)->takeBody()->readAll(), 'css');
        Assert::same($router(getRequest('/'), $this->context)->takeBody()->readAll(), 'home');
        Assert::same($router(getRequest('/a%2Fb'), $this->context)->status, Status::BAD_REQUEST);
        Assert::same($router(new Request(Method::POST, '/'), $this->context)->status, Status::METHOD_NOT_ALLOWED);
    }

    public function returnsNotFoundForAnUnmatchedEmptyPath(): void
    {
        $router = new Router([]);
        $request = new Request(Method::GET, 'http://example.test');

        Assert::same($router($request, $this->context)->status, Status::NOT_FOUND);
    }

    public function delegatesSpecialTargetsToTheDefaultHandler(): void
    {
        $reply = new Response(body: 'fallback');
        $router = new Router([], defaultHandler: static fn() => $reply);

        Assert::same($router(new Request(Method::OPTIONS, 'http://example.test'), $this->context), $reply);
        Assert::same($router(new Request(Method::CONNECT, '//example.test:443'), $this->context), $reply);
    }

    public function formatsRoutingErrorsAndPreservesAllow(): void
    {
        $router = new Router([new Route(Method::POST, '/', static fn() => new Response())]);
        $address = new UnixAddress('');
        $context = new Context($address, $address, null, new TextErrorHandler(), new Dispatcher());

        $response = $router(getRequest('/'), $context);
        Assert::same($response->takeBody()->readAll(), 'GET 405');
        Assert::same($response->headers->get('Allow'), Method::POST);
        Assert::same($router(getRequest('/missing'), $context)->takeBody()->readAll(), 'GET 404');
    }

    public function ordersOverlappingParameterizedShapesLeftToRight(): void
    {
        $router = new Router([
            new Route(Method::GET, '/{a}/{b}/info', static fn() => new Response(body: 'info')),
            new Route(Method::GET, '/{a}/files/{b}', static fn() => new Response(body: 'files')),
            new Route(Method::GET, '/users/{a}/{b}', static fn() => new Response(body: 'users')),
        ]);

        Assert::same($router(getRequest('/users/files/1'), $this->context)->takeBody()->readAll(), 'users');
        Assert::same($router(getRequest('/other/files/1'), $this->context)->takeBody()->readAll(), 'files');
        Assert::same($router(getRequest('/one/two/info'), $this->context)->takeBody()->readAll(), 'info');
    }

    public function prefersExplicitStaticHeadAndKeepsNumericMethodsAsStrings(): void
    {
        $router = new Router([
            new Route('123', '/', static fn() => new Response(body: 'numeric')),
            new Route(Method::GET, '/', static fn() => new Response(body: 'get')),
            new Route(Method::HEAD, '/', static fn() => new Response(body: 'head')),
        ]);
        $uri = new Uri('/');

        Assert::same($router(new Request(Method::HEAD, $uri), $this->context)->takeBody()->readAll(), 'head');
        Assert::same($router(new Request('123', $uri), $this->context)->takeBody()->readAll(), 'numeric');

        $response = $router(new Request(Method::POST, $uri), $this->context);
        Assert::same($response->status, Status::METHOD_NOT_ALLOWED);
        Assert::same($response->headers->get('Allow'), '123, GET, HEAD');
        $result = $router->match(new Request(Method::POST, $uri));
        Assert::instanceOf($result, MethodNotAllowed::class);
        Assert::same($result->allowedMethods, ['123', 'GET', 'HEAD']);
    }

    public function fallsBackToAnyMethodOnlyOnTheSelectedPath(): void
    {
        $forward = static fn(Request $request) => new Response(body: $request->method);
        $get = static fn() => new Response(body: 'local');
        $fallback = Route::any('/storage/{path+}', $forward);
        $router = new Router([
            $fallback,
            new Route(Method::GET, '/storage/{name+}', $get),
            Route::get('/storage/status', $get),
            new Route('*', '/exact[/]', $get),
            new Route(null, '/exact[/]', $forward),
        ]);

        $custom = new Request('PROPFIND', new Uri('/storage/a.txt'));
        Assert::same($router($custom, $this->context)->takeBody()->readAll(), 'PROPFIND');
        $match = $router->match($custom);
        Assert::instanceOf($match, RouteMatch::class);
        Assert::same($match->route, $fallback);
        Assert::same($match->method, 'PROPFIND');
        Assert::same($match->params, ['path' => 'a.txt']);
        Assert::same(self::matched($router, new Request('HEAD', $custom->uri))->method, 'GET');
        $denied = $router(new Request('DELETE', new Uri('/storage/status')), $this->context);
        Assert::same($denied->status, 405);
        Assert::same($denied->headers->get('Allow'), 'GET, HEAD');
        $exact = new Uri('/exact/');
        Assert::same($router(new Request('*', $exact), $this->context)->takeBody()->readAll(), 'local');
        Assert::same(self::matched($router, new Request('HEAD', $exact))->method, 'HEAD');
    }

    public function keepsTheRequestMethodAndDefaultsOnADynamicFallback(): void
    {
        $handler = static fn() => new Response();
        $route = new Route(null, '/{group}/items/{id?}', $handler, defaults: ['id' => 'latest']);
        Assert::null($route->methods);
        $router = new Router([$route]);
        $match = self::matched($router, new Request('HEAD', new Uri('/news/items')));
        Assert::same($match->method, 'HEAD');
        Assert::same($match->params, ['group' => 'news', 'id' => 'latest']);
        Assert::same(self::matched($router, getRequest('/news/items/42'))->params, ['group' => 'news', 'id' => '42']);
    }

    public function rejectsDuplicateFallbackShapes(): void
    {
        $handler = static fn() => new Response();
        Expect::exception(\InvalidArgumentException::class)->withMessage('Duplicate fallback route /{b}.');
        new Router([new Route(null, '/{a}', $handler), new Route(null, '/{b}', $handler)]);
    }

    public function decodesOnceAndPreservesSegmentBoundaries(): void
    {
        $router = new Router([
            new Route(
                methods: Method::GET,
                pattern: '/{value}',
                handler: static fn($request) => new Response(body: routeMatch($request)?->param('value') ?? ''),
            ),
        ]);

        foreach (['/%2f', '/a%2Fb', '/%00'] as $path) {
            Assert::same($router(getRequest($path), $this->context)->status, Status::BAD_REQUEST);
        }

        foreach (['/%252F' => '%2F', '/%2e%2e' => '..', '/%3F' => '?'] as $path => $value) {
            $request = getRequest($path);
            Assert::same($router($request, $this->context)->takeBody()->readAll(), $value);
        }

        foreach (['/', '/x/'] as $path) {
            Assert::same($router(getRequest($path), $this->context)->status, Status::NOT_FOUND);
        }
    }

    public function keepsNestedMatchesIndependent(): void
    {
        $get = new Request(Method::HEAD, new Uri('/a/items/42'));
        $inner = new Router([
            new Route(Method::GET, '/{group}/items/{name}', function ($request, $context) use ($get) {
                Assert::same($request, $get);
                Assert::same($context, $this->context);
                Assert::same(routeMatch($request)?->params, ['group' => 'a', 'name' => '42']);

                Assert::same(routeParam($request, 'name'), '42');
                Assert::null(routeParam($request, 'team'));

                return new Response();
            }),
        ]);
        $outer = static function (Request $request, Context $context) use ($inner) {
            $response = $inner($request, $context);
            $match = routeMatch($request);
            Assert::instanceOf($match, RouteMatch::class);
            Assert::same($match->method, Method::GET);
            Assert::same($match->route->pattern, '/{team}/items/{item}');
            Assert::null($match->param('missing'));
            Assert::same($match->params, ['team' => 'a', 'item' => '42']);

            return $response;
        };
        $outer = Pipeline::wrap($outer, [
            new CallbackMiddleware(static function ($request, $context, $next) {
                Assert::same(routeParam($request, 'team'), 'a');

                return $next();
            }),
        ]);
        $router = new Router((static function () use ($outer) {
            yield new Route([Method::POST, Method::GET], '/{team}/items/{item}', $outer);
            yield new Route(Method::PUT, '/{group}/items/{name}', static fn() => new Response());
        })());
        Assert::same($router($get, $this->context)->status, Status::OK);
        Assert::null(routeMatch($get));
        Assert::null(routeParam($get, 'team'));
        $response = $router($get->withMethod('get'), $this->context);
        Assert::same($response->status, Status::METHOD_NOT_ALLOWED);
        Assert::same($response->headers->get('Allow'), 'POST, GET, PUT, HEAD');
        Assert::same($router(getRequest('/a/items/'), $this->context)->status, Status::NOT_FOUND);
    }

    public function matchesTheRootWhenAnAbsoluteUriHasAnEmptyPath(): void
    {
        $router = new Router([new Route(Method::GET, '/', static fn() => new Response(Status::NO_CONTENT))]);

        foreach (['http://example.test', 'http://example.test?q=1', 'http://example.test/'] as $uri) {
            Assert::same($router(getRequest($uri), $this->context)->status, Status::NO_CONTENT);
        }

        $options = new Request(Method::OPTIONS, new Uri('http://example.test'));
        $connect = new Request(Method::CONNECT, new Uri('//example.test:443'));
        Assert::same($router($options, $this->context)->status, Status::NO_CONTENT);
        Assert::same($router($connect, $this->context)->status, Status::NOT_IMPLEMENTED);
        $options = $options->withUri(new Uri('http://example.test?q=1'));
        Assert::same($router($options, $this->context)->status, Status::METHOD_NOT_ALLOWED);
    }

    public function rejectsDuplicateShapesForTheSameMethod(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Duplicate route GET /{name}/sub/{part}.');
        new Router([
            new Route(Method::GET, '/{id}/sub/{part}', static fn() => new Response()),
            new Route(Method::GET, '/{name}/sub/{part}', static fn() => new Response()),
        ]);
    }

    /**
     * @return iterable<string, array{non-empty-string, string, string}>
     */
    public static function invalidRoutes(): iterable
    {
        yield 'optional before literal' => [
            'GET',
            '/{id?}/end',
            "Optional parameters must be trailing in '/{id?}/end'.",
        ];
        yield 'nonfinal remainder' => ['GET', '/{id*}/end', "Invalid remainder in '/{id*}/end'."];
        yield 'invalid method' => ['GE T', '/', "Invalid HTTP method 'GE T'."];
        yield 'newline in method' => ["GET\n", '/', "Invalid HTTP method 'GET\n'."];
        yield 'newline in name' => [Method::GET, "/{id\n}", "Invalid parameter '{id\n}' in '/{id\n}'."];
        yield 'leading digit in name' => [Method::GET, '/{1x}', "Invalid parameter '{1x}' in '/{1x}'."];
        yield 'relative path' => [Method::GET, 'abc', "Invalid route pattern 'abc'."];
        yield 'NUL in pattern' => [Method::GET, "/a\0b", "Invalid route pattern '/a\0b'."];
        yield 'empty name' => [Method::GET, '/{}', "Invalid parameter '{}' in '/{}'."];
        yield 'numeric name' => [Method::GET, '/{42}', "Invalid parameter '{42}' in '/{42}'."];
        yield 'punctuation in name' => [Method::GET, '/{id!}', "Invalid parameter '{id!}' in '/{id!}'."];
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('invalidRoutes')]
    public function rejectsInvalidDefinitions(string $method, string $pattern, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);
        new Router([new Route($method, $pattern, static fn() => new Response())]);
    }

    public function rejectsDuplicateStaticRoutes(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Duplicate route GET /about.');
        new Router([
            new Route(Method::GET, '/about', static fn() => new Response()),
            new Route(Method::GET, '/about', static fn() => new Response()),
        ]);
    }

    public function rejectsDuplicateTrailingParameters(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Duplicate route GET /users/{name}.');
        new Router([
            new Route(Method::GET, '/users/{id}', static fn() => new Response()),
            new Route(Method::GET, '/users/{name}', static fn() => new Response()),
        ]);
    }

    public function rejectsRepeatedParameterNamesAtStartup(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage("Invalid parameter '{id}' in '/{id}/{id}'.");
        new Router([new Route(Method::GET, '/{id}/{id}', static fn() => new Response())]);
    }

    public function looksUpOptionalCompoundAndRemainderParametersWithoutDispatch(): void
    {
        $handler = static fn() => throw new \LogicException('Unexpected dispatch.');
        $router = new Router([
            new Route('GET', '/archive/{year?}/{month?}', $handler, defaults: ['month' => '01']),
            new Route('GET', '/files/{path*}', $handler),
            new Route('GET', '/reports/{year}-{month}.csv', $handler, requirements: ['year' => '[0-9]{4}']),
            new Route('GET', '/assets/{path+}', $handler, requirements: ['path' => '[a-z/]+']),
        ]);
        Assert::same(self::matched($router, getRequest('/archive'))->params, ['month' => '01']);
        Assert::same(
            actual: self::matched($router, getRequest('/archive/2026/11'))->params,
            expected: ['year' => '2026', 'month' => '11'],
        );
        Assert::same(self::matched($router, getRequest('/files'))->params, ['path' => '']);
        Assert::same(self::matched($router, getRequest('/files/a/b/'))->params, ['path' => 'a/b/']);
        Assert::same($router->match(getRequest('/assets/')), RouteFailure::NotFound);
        Assert::same($router->match(getRequest('/assets/a/42')), RouteFailure::NotFound);
        Assert::same(self::matched($router, getRequest('/assets/a'))->params, ['path' => 'a']);
        $report = self::matched($router, getRequest('/reports/2026-11.csv'));
        Assert::same($report->params, ['year' => '2026', 'month' => '11']);
        Assert::same($router->match(getRequest('/reports/current-11.csv')), RouteFailure::NotFound);
    }

    public function matchesOneOptionalTrailingSlash(): void
    {
        $handler = static fn() => new Response();
        $router = new Router([
            new Route('GET', '/docs[/]', $handler),
            new Route('GET', '/archive/{year?}[/]', $handler),
        ]);
        Assert::same(self::matched($router, getRequest('/docs'))->route->pattern, '/docs[/]');
        Assert::same(self::matched($router, getRequest('/docs/'))->route->pattern, '/docs[/]');
        Assert::same($router->match(getRequest('/docs//')), RouteFailure::NotFound);
        Assert::same(self::matched($router, getRequest('/archive/'))->params, []);
        Assert::same(self::matched($router, getRequest('/archive/2026/'))->params, ['year' => '2026']);
    }

    public function rejectsAnExplicitRouteCoveredByAnOptionalSlash(): void
    {
        $handler = static fn() => new Response();
        Expect::exception(\InvalidArgumentException::class)->withMessage('Duplicate route GET /docs/{name}/.');
        new Router([
            new Route('GET', '/docs/{id}[/]', $handler),
            new Route('GET', '/docs/{name}/', $handler),
        ]);
    }

    public function prefersTheShortestCompleteSplitBetweenAdjacentParameters(): void
    {
        $router = new Router([new Route(
            'GET',
            '/{first}{second}{third}.txt',
            static fn() => new Response(),
            requirements: ['first' => '[0-9]+', 'second' => '\d{2,3}'],
        )]);
        $match = self::matched($router, getRequest('/123456.txt'));
        Assert::same($match->params, ['first' => '1', 'second' => '23', 'third' => '456']);
        Assert::same($router->match(getRequest('/123.txt')), RouteFailure::NotFound);
    }

    public function keepsCaptureGroupsLocalToEachRequirement(): void
    {
        $handler = static fn() => new Response();
        $repeat = '([a-z])\1';
        $named = '(?<p1>[0-9]+)';
        $router = new Router([
            new Route('GET', '/single/{x}', $handler, requirements: ['x' => $named]),
            new Route('GET', '/pair/{x}-{y}', $handler, requirements: ['x' => $repeat, 'y' => $named]),
            new Route('GET', '/tail/{x+}', $handler, requirements: ['x' => $repeat]),
        ]);
        Assert::same(self::matched($router, getRequest('/single/123'))->params, ['x' => '123']);
        Assert::same(self::matched($router, getRequest('/pair/aa-123'))->params, ['x' => 'aa', 'y' => '123']);
        Assert::same($router->match(getRequest('/pair/ab-123')), RouteFailure::NotFound);
        Assert::same(self::matched($router, getRequest('/tail/aa'))->params, ['x' => 'aa']);
    }

    public function preservesAnEmptyLiteralBeforeAnOmittedRootSuffix(): void
    {
        $handler = static fn() => new Response();
        foreach (['//{id?}' => [], '//{tail*}' => ['tail' => '']] as $pattern => $params) {
            $router = new Router([new Route('GET', $pattern, $handler)]);
            Assert::same(self::matched($router, getRequest('/'))->params, $params);
        }
    }

    public function choosesTheCompletePathAcrossDynamicBranches(): void
    {
        $handler = static fn() => new Response();
        $router = new Router([
            new Route('GET', '/items/{value}', $handler),
            new Route('GET', '/items/{path*}', $handler),
            Route::post('/items/{id}', $handler, requirements: ['id' => '[0-9]+']),
            new Route('GET', '/items/{key}.csv/detail', $handler),
        ]);

        $response = $router(getRequest('/items/42'), $this->context);
        Assert::same($response->status, Status::METHOD_NOT_ALLOWED);
        Assert::same($response->headers->get('Allow'), 'POST');
        Assert::same(self::matched($router, getRequest('/items/a.csv'))->params, ['value' => 'a.csv']);
        Assert::same(self::matched($router, getRequest('/items/a.csv/detail'))->params, ['key' => 'a']);
        Assert::same(self::matched($router, getRequest('/items/a.csv/other'))->params, ['path' => 'a.csv/other']);
        Assert::same($router->match(getRequest('/items/a%2Fb')), RouteFailure::BadPath);
    }

    public function servesThesisRequests(): void
    {
        $handler = new Router([
            new Route(
                methods: Method::GET,
                pattern: '/{id}',
                handler: static fn($request) => new Response(body: routeMatch($request)?->param('id') ?? ''),
            ),
            new Route(Method::HEAD, '/{id}', static fn() => new Response(Status::NO_CONTENT)),
        ]);
        $missing = $handler(getRequest('/two/parts'), $this->context);
        Assert::same($missing->status, Status::NOT_FOUND);
        Assert::null($missing->headers->get('Allow'));
        Assert::same($handler(getRequest('/a%2Fb'), $this->context)->status, Status::BAD_REQUEST);
        $uri = new Uri('/abc?q=unused');
        Assert::same($handler(new Request(Method::GET, $uri), $this->context)->takeBody()->readAll(), 'abc');
        Assert::same($handler(new Request(Method::HEAD, $uri), $this->context)->status, Status::NO_CONTENT);
        $response = $handler(new Request(Method::POST, $uri), $this->context);
        Assert::same($response->status, Status::METHOD_NOT_ALLOWED);
        Assert::same($response->headers->get('allow'), 'GET, HEAD');
    }

    private static function user(Request $request): Response
    {
        return new Response(body: $request->method . ' ' . (routeMatch($request)?->param('id') ?? ''));
    }

    private static function matched(Router $router, Request $request): RouteMatch
    {
        $result = $router->match($request);
        Assert::instanceOf($result, RouteMatch::class);

        return $result;
    }
}
