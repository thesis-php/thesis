# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The HTTP messages, server and HTTP/1 codec ship together as `thesis/http`.

### Removed

- Remove the outgoing HTTP client, connection pool, client middleware and shared `Message` class.

### Changed

- Server handlers receive a non-cloneable `Request` with read-only metadata.

### Fixed

- Return 204 for server-wide OPTIONS and 501 for CONNECT when the router has no default handler.

### Added

- Stream the request body and its trailers back to the caller with `Request::echo()`.

- Stream a local file as a request or response body with `new FileBody($path)`.
- Serve files through GET and HEAD routes with `Server\StaticFiles`.

- Consume the rest of a body and receive its trailers with `Body::discard()`.

- Support installations without ext-zlib. Server gzip compression uses this optional extension.

- Add status constants and reason phrases for 205 Reset Content and 300 Multiple Choices.

- Negotiate streaming gzip compression with the server middleware `Server\Gzip`.

- Supply headers and string body trailers as arrays, request URIs as strings, and queries as encoded strings or parameter arrays.
- Append field lines with `Response::withAddedHeaders()`.
- Append or remove query parameters with `Query::withAdded()` and `without()`.

- Create JSON and HTML responses using `Response::json()` and `Response::html()`.
- Set a JSON body with `withJson()` and take and decode it with `takeJson()` on requests and responses. Both methods enable `JSON_THROW_ON_ERROR` with any supplied flags.

- Pass a default handler to `Router` to handle requests for unknown paths.
- Use `Router::$list` to read the original routes in registration order.
- Cancel the active server exchange when the peer disconnects after sending the request body.

- Log server events and optional per-request access records to a PSR-3 logger with `ServerLogger`.
- Observe server, connection, request and routing events through a dispatcher in place of the server error callback.
  RequestStarted, RouteMatched and RequestEnded carry the request object. The server emits RequestEnded with duration, status and response write outcome.
  Handlers and middleware can publish application events through `Context::$dispatcher`.

- Server middleware receives the connection context as the second argument of `handle()`, before `$next`.
- Serve a default HTML page for routing errors and server-generated 500 responses.
- Customize routing errors and server-generated 500 responses with `Server\ErrorHandler`.
  Access it through `Context::$errorHandler`. Creating a context requires both the error handler and the dispatcher.
- Read the active route in handlers and middleware with `Server\routeMatch($request)` or a single parameter with `Server\routeParam($request, $name)`.
- Serve HEAD with a GET handler. The server suppresses the response body and preserves representation headers.
- Create routes with named constructors for each standard HTTP method and `Route::any()` for a fallback.
  Use `pattern:` for the path template and read it from `Route::$pattern`.
- Register one or more methods per route or pass `methods: null` to accept any remaining method on that path. Route requests by method and path with brace parameters, optional suffixes, remainders, constraints, defaults and compound segments.
  Inspect the selected route and match or the failure reason (including allowed methods for 405) with `Router::match()` and accept an optional trailing slash with `[/]`. Dispatch supports HEAD fallback and 405 with Allow.
- Copies of `Response` share a single `takeBody()` handoff.
- Forwarding a message preserves the reader and its final trailer fields.
- Share immutable headers, ordered query parameters, HTTP methods and status codes across roles.
- Read streaming bodies incrementally or collect a whole unread payload. Replayable bodies can open independent readers.
- Intercept server exchanges with `Server\Middleware`.
  Pass an iterable to `Server\Pipeline::wrap()` to run ordered middleware and retry after the next handler returns or throws.
- Serve multiple TCP or Unix endpoints with one `Server`, with TLS and connection limits per endpoint.
  Start listening with `Server::start($group)` and wait for active exchanges with `stop()`.
- Serve TCP and Unix connections concurrently with a bounded number of open sockets.
- Stream HTTP/1 requests and responses with fixed-length and chunked bodies, trailers and keep-alive.
  Set `maxRequestsPerConnection` to limit keep-alive reuse.
- Bound request sizes and connection I/O waits. Stop accepting connections and give active exchanges time to finish.
- Preserve `Content-Length` as representation metadata for eligible `HEAD` and `304` responses.
