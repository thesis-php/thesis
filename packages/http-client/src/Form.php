<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Thesis\Http\Headers;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\HttpClient\Internal\MultipartBody;
use Uri\Rfc3986\Uri;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * @api
 */
final class Form
{
    /**
     * @param iterable<int|string, string|list<string>> $fields
     */
    public static function urlEncoded(string|Uri $uri, iterable $fields, Headers $headers = EMPTY_HEADERS): Request
    {
        $pairs = [];
        foreach ($fields as $name => $values) {
            $name = urlencode((string) $name);
            foreach ((array) $values as $value) {
                $pairs[] = $name . '=' . urlencode($value);
            }
        }

        return new Request(
            method: Method::POST,
            uri: \is_string($uri) ? new Uri($uri) : $uri,
            headers: $headers->with('content-type', 'application/x-www-form-urlencoded'),
            body: implode('&', $pairs),
        );
    }

    /**
     * @param iterable<FormPart> $parts
     */
    public static function multipart(string|Uri $uri, iterable $parts, Headers $headers = EMPTY_HEADERS): Request
    {
        $boundary = bin2hex(random_bytes(24));

        return new Request(
            method: Method::POST,
            uri: \is_string($uri) ? new Uri($uri) : $uri,
            headers: $headers->with('content-type', 'multipart/form-data; boundary=' . $boundary),
            body: new MultipartBody($boundary, $parts),
        );
    }

    private function __construct() {}
}
