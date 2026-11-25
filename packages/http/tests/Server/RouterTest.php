<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Stub\TextErrorHandler;
use Thesis\Http\Status;
use Thesis\Net\UnixAddress;
use Uri\Rfc3986\Uri;

#[Test]
final readonly class RouterTest
{
    private Context $context;

    public function __construct()
    {
        $this->context = new Context(new UnixAddress(''), new UnixAddress(''), null);
    }

    public function choosesTheMostSpecificCompletePathBeforeTheMethod(): void
    {
        $router = new Router([
            new Route(Method::GET, '/users/:id', self::user(...)),
            new Route(Method::POST, '/users/me', static fn() => new Response(body: 'me')),
            new Route(
                method: Method::GET,
                route: '/:group/me/:id',
                handler: static fn($request, $context, $match) => new Response(body: implode('/', $match->params)),
            ),
            new Route(Method::GET, '/users/:id/info', static fn() => new Response(body: 'info')),
        ]);

        $head = new Request(Method::HEAD, new Uri('/users/a+b%20c'));
        Assert::same($router($head, $this->context)->takeBody()->readAll(), 'HEAD a+b c');
        $post = new Request(Method::POST, new Uri('/users/m%65'));
        Assert::same($router($post, $this->context)->takeBody()->readAll(), 'me');
        Assert::same($router(Request::get('/users/me/info'), $this->context)->takeBody()->readAll(), 'info');
        Assert::same($router(Request::get('/users/me/42'), $this->context)->takeBody()->readAll(), 'users/42');

        $response = $router(Request::get('/users/me'), $this->context);
        Assert::same($response->status, Status::METHOD_NOT_ALLOWED);
        Assert::same($response->headers->get('Allow'), Method::POST);
    }

    public function formatsRoutingErrorsAndPreservesAllow(): void
    {
        $router = new Router([new Route(Method::POST, '/', static fn() => new Response())]);
        $address = new UnixAddress('');
        $context = new Context($address, $address, null, new TextErrorHandler());

        $response = $router(Request::get('/'), $context);
        Assert::same($response->takeBody()->readAll(), 'GET 405');
        Assert::same($response->headers->get('Allow'), Method::POST);
        Assert::same($router(Request::get('/missing'), $context)->takeBody()->readAll(), 'GET 404');
    }

    public function ordersOverlappingParameterizedShapesLeftToRight(): void
    {
        $router = new Router([
            new Route(Method::GET, '/:a/:b/info', static fn() => new Response(body: 'info')),
            new Route(Method::GET, '/:a/files/:b', static fn() => new Response(body: 'files')),
            new Route(Method::GET, '/users/:a/:b', static fn() => new Response(body: 'users')),
        ]);

        Assert::same($router(Request::get('/users/files/1'), $this->context)->takeBody()->readAll(), 'users');
        Assert::same($router(Request::get('/other/files/1'), $this->context)->takeBody()->readAll(), 'files');
        Assert::same($router(Request::get('/one/two/info'), $this->context)->takeBody()->readAll(), 'info');
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
    }

    public function decodesOnceAndPreservesSegmentBoundaries(): void
    {
        $router = new Router([
            new Route(
                method: Method::GET,
                route: '/:value',
                handler: static fn($request, $context, $match) => new Response(body: $match->param('value') ?? ''),
            ),
        ]);

        foreach (['/%2f', '/a%2Fb', '/%00'] as $path) {
            Assert::same($router(Request::get($path), $this->context)->status, Status::BAD_REQUEST);
        }

        foreach (['/%252F' => '%2F', '/%2e%2e' => '..', '/%3F' => '?'] as $path => $value) {
            $request = Request::get($path);
            Assert::same($router($request, $this->context)->takeBody()->readAll(), $value);
        }

        foreach (['/', '/x/'] as $path) {
            Assert::same($router(Request::get($path), $this->context)->status, Status::NOT_FOUND);
        }
    }

    public function keepsNestedMatchesIndependent(): void
    {
        $get = new Request(Method::HEAD, new Uri('/a/items/42'));
        $inner = new Router([
            new Route(Method::GET, '/:group/items/:name', function ($request, $context, $match) use ($get) {
                Assert::same($request, $get);
                Assert::same($context, $this->context);
                Assert::same($match->params, ['group' => 'a', 'name' => '42']);

                return new Response();
            }),
        ]);
        $outer = static function (Request $request, Context $context, RouteMatch $match) use ($inner) {
            $response = $inner($request, $context);
            Assert::same($match->method, Method::GET);
            Assert::same($match->pattern, '/:team/items/:item');
            Assert::null($match->param('missing'));
            Assert::same($match->params, ['team' => 'a', 'item' => '42']);

            return $response;
        };
        $router = new Router((static function () use ($outer) {
            yield new Route(Method::GET, '/:team/items/:item', $outer);
            yield new Route(Method::PUT, '/:group/items/:name', static fn() => new Response());
        })());
        Assert::same($router($get, $this->context)->status, Status::OK);
        $response = $router($get->withMethod('get'), $this->context);
        Assert::same($response->status, Status::METHOD_NOT_ALLOWED);
        Assert::same($response->headers->get('Allow'), 'GET, PUT, HEAD');
        Assert::same($router(Request::get('/a/items/'), $this->context)->status, Status::NOT_FOUND);
    }

    public function matchesTheRootWhenAnAbsoluteUriHasAnEmptyPath(): void
    {
        $router = new Router([new Route(Method::GET, '/', static fn() => new Response(Status::NO_CONTENT))]);

        foreach (['http://example.test', 'http://example.test?q=1', 'http://example.test/'] as $uri) {
            Assert::same($router(Request::get($uri), $this->context)->status, Status::NO_CONTENT);
        }

        $options = new Request(Method::OPTIONS, new Uri('http://example.test'));
        $connect = new Request(Method::CONNECT, new Uri('//example.test:443'));
        Assert::same($router($options, $this->context)->status, Status::NOT_FOUND);
        Assert::same($router($connect, $this->context)->status, Status::NOT_FOUND);
        $options = $options->withUri(new Uri('http://example.test?q=1'));
        Assert::same($router($options, $this->context)->status, Status::METHOD_NOT_ALLOWED);
    }

    public function rejectsDuplicateShapesForTheSameMethod(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Duplicate route GET /:name/sub/:part.');
        new Router([
            new Route(Method::GET, '/:id/sub/:part', static fn() => new Response()),
            new Route(Method::GET, '/:name/sub/:part', static fn() => new Response()),
        ]);
    }

    /**
     * @return iterable<string, array{non-empty-string, string, string}>
     */
    public static function invalidRoutes(): iterable
    {
        yield 'invalid method' => ['GE T', '/', "Invalid HTTP method 'GE T'."];
        yield 'newline in method' => ["GET\n", '/', "Invalid HTTP method 'GET\n'."];
        yield 'newline in name' => [Method::GET, "/:id\n", "Invalid parameter ':id\n' in '/:id\n'."];
        yield 'leading digit in name' => [Method::GET, '/:1x', "Invalid parameter ':1x' in '/:1x'."];
        yield 'relative path' => [Method::GET, 'abc', "Invalid route pattern 'abc'."];
        yield 'NUL in pattern' => [Method::GET, "/a\0b", "Invalid route pattern '/a\0b'."];
        yield 'empty name' => [Method::GET, '/:', "Invalid parameter ':' in '/:'."];
        yield 'numeric name' => [Method::GET, '/:42', "Invalid parameter ':42' in '/:42'."];
        yield 'punctuation in name' => [Method::GET, '/:id?', "Invalid parameter ':id?' in '/:id?'."];
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
        Expect::exception(\InvalidArgumentException::class)->withMessage('Duplicate route GET /users/:name.');
        new Router([
            new Route(Method::GET, '/users/:id', static fn() => new Response()),
            new Route(Method::GET, '/users/:name', static fn() => new Response()),
        ]);
    }

    public function rejectsRepeatedParameterNamesAtStartup(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage("Invalid parameter ':id' in '/:id/:id'.");
        new Router([new Route(Method::GET, '/:id/:id', static fn() => new Response())]);
    }

    public function servesThesisRequests(): void
    {
        $handler = new Router([
            new Route(
                method: Method::GET,
                route: '/:id',
                handler: static fn($request, $context, $match) => new Response(body: $match->param('id') ?? ''),
            ),
            new Route(Method::HEAD, '/:id', static fn() => new Response(Status::NO_CONTENT)),
        ]);
        $missing = $handler(Request::get('/two/parts'), $this->context);
        Assert::same($missing->status, Status::NOT_FOUND);
        Assert::null($missing->headers->get('Allow'));
        Assert::same($handler(Request::get('/a%2Fb'), $this->context)->status, Status::BAD_REQUEST);
        $uri = new Uri('/abc?q=unused');
        Assert::same($handler(new Request(Method::GET, $uri), $this->context)->takeBody()->readAll(), 'abc');
        Assert::same($handler(new Request(Method::HEAD, $uri), $this->context)->status, Status::NO_CONTENT);
        $response = $handler(new Request(Method::POST, $uri), $this->context);
        Assert::same($response->status, Status::METHOD_NOT_ALLOWED);
        Assert::same($response->headers->get('allow'), 'GET, HEAD');
    }

    private static function user(Request $request, Context $context, RouteMatch $match): Response
    {
        return new Response(body: $request->method . ' ' . ($match->param('id') ?? ''));
    }
}
