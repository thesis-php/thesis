<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Thesis\Http\Headers;
use Thesis\Http\Method;
use Thesis\Http\ReplayableBody;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Uri\Rfc3986\Uri;
use const Thesis\Http\EMPTY_BODY;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * Follows HTTP redirects. Returns the last response when the limit is reached or the request cannot be forwarded.
 *
 * @api
 */
final readonly class FollowRedirects implements Middleware
{
    /**
     * @param non-negative-int $maxRedirects
     */
    public function __construct(
        private int $maxRedirects = 10,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        if ($this->maxRedirects === 0) {
            return $next($request);
        }

        $upload = $request->takeBody();
        $request = $request->withBody($upload);
        for ($followed = 0;; ++$followed) {
            $response = $next($request);
            if ($followed === $this->maxRedirects || !\in_array($response->status, [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $locations = $response->headers->all('location');
            if (\count($locations) !== 1 || $locations[0] === '') {
                return $response;
            }

            $target = Uri::parse($locations[0], $request->uri);
            if ($target === null
                || !\in_array($target->getScheme(), ['http', 'https'], true)
                || $target->getRawHost() === null
                || $target->getRawHost() === ''
                || $target->getRawUserInfo() !== null
                || ($request->uri->getScheme() === 'https' && $target->getScheme() === 'http')
            ) {
                return $response;
            }

            $method = $request->method;
            $rewrite = $response->status === 303 || ($response->status <= 302 && $method === Method::POST);
            if (!$rewrite && !$upload instanceof ReplayableBody) {
                return $response;
            }

            $response->takeBody()->close();
            $headers = self::headers($request, $target);
            if ($rewrite) {
                $method = $method === Method::HEAD ? Method::HEAD : Method::GET;
                $upload = EMPTY_BODY;
                foreach ($headers as $name => $values) {
                    if (str_starts_with($name, 'content-') || \in_array($name, ['expect', 'trailer', 'digest'], true)) {
                        $headers = $headers->without($name);
                    }
                }
            } else {
                $upload = $upload->fresh();
            }

            $request = new Request(method: $method, uri: $target->withFragment(null), headers: $headers, body: $upload);
        }
    }

    private static function headers(Request $request, Uri $target): Headers
    {
        $source = $request->uri;
        $defaultPort = $source->getScheme() === 'https' ? 443 : 80;
        if ($source->getScheme() === $target->getScheme()
            && strcasecmp($source->getRawHost() ?? '', $target->getRawHost() ?? '') === 0
            && ($source->getPort() ?? $defaultPort) === ($target->getPort() ?? $defaultPort)
        ) {
            return $request->headers;
        }

        // A custom field can carry credentials. Only forward known representation and negotiation fields.
        $headers = EMPTY_HEADERS;
        foreach (['accept', 'accept-encoding', 'accept-language', 'content-type', 'content-encoding',
            'content-language', 'content-digest'] as $name) {
            if ($request->headers->has($name)) {
                $headers = $headers->with($name, $request->headers->all($name));
            }
        }

        return $headers;
    }
}
