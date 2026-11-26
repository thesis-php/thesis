<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Thesis\Http\FileBody;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Status;
use Thesis\Io\ReadFailed;
use function Thesis\Http\Internal\decodeRequestPath;

/**
 * Streams files from a directory in response to GET and HEAD requests.
 *
 * @api
 */
final readonly class StaticFiles
{
    private string $directory;

    private ExtensionMimeTypeDetector $types;

    /**
     * @throws \InvalidArgumentException if the directory does not exist
     */
    public function __construct(string $directory)
    {
        if ($directory === '' || !is_dir($directory)) {
            throw new \InvalidArgumentException("Invalid document root '{$directory}'.");
        }
        if (!str_starts_with($directory, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new \InvalidArgumentException("Cannot resolve relative document root '{$directory}'.");
            }
            $directory = $cwd . '/' . $directory;
        }
        $this->directory = $directory;
        $this->types = new ExtensionMimeTypeDetector();
    }

    /**
     * Serves the directory below a URL prefix through the router.
     */
    public function route(string $prefix): Route
    {
        return Route::get(rtrim($prefix, '/') . '/{path+}', function (Request $request, Context $context) {
            $path = routeParam($request, 'path');
            \assert($path !== null);

            return $this->serve($path, $request, $context);
        });
    }

    public function __invoke(Request $request, Context $context): Response
    {
        if ($request->method !== Method::GET && $request->method !== Method::HEAD) {
            $response = $context->errorHandler->handleError(Status::METHOD_NOT_ALLOWED, $request);

            return $response->withHeader('Allow', 'GET, HEAD');
        }

        $path = decodeRequestPath($request->uri->getRawPath());
        if ($path === null) {
            return $context->errorHandler->handleError(Status::BAD_REQUEST, $request);
        }
        if (!str_starts_with($path, '/')) {
            return $context->errorHandler->handleError(Status::NOT_FOUND, $request);
        }

        return $this->serve(substr($path, 1), $request, $context);
    }

    private function serve(string $name, Request $request, Context $context): Response
    {
        // Another process may have replaced a file or retargeted a symlink since the last request.
        clearstatcache(clear_realpath_cache: true);
        $root = realpath($this->directory);
        if ($root === false) {
            return $context->errorHandler->handleError(Status::NOT_FOUND, $request);
        }
        $prefix = rtrim($root, '/') . '/';
        $file = realpath($prefix . $name);
        if ($file === false || !str_starts_with($file, $prefix)) {
            return $context->errorHandler->handleError(Status::NOT_FOUND, $request);
        }

        try {
            $body = new FileBody($file);
        } catch (ReadFailed) {
            return $context->errorHandler->handleError(Status::NOT_FOUND, $request);
        }

        $type = $this->types->detectMimeTypeFromPath($name) ?? 'application/octet-stream';
        if ($request->method === Method::HEAD) {
            $body->close();

            return new Response(headers: ['Content-Type' => $type, 'Content-Length' => (string) $body->size]);
        }

        return new Response(headers: ['Content-Type' => $type], body: $body);
    }
}
