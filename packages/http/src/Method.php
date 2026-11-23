<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * The methods defined by RFC 9110, plus PATCH from RFC 5789. Any token is a valid method.
 *
 * @api
 */
final class Method
{
    public const string GET = 'GET';
    public const string HEAD = 'HEAD';
    public const string POST = 'POST';
    public const string PUT = 'PUT';
    public const string PATCH = 'PATCH';
    public const string DELETE = 'DELETE';
    public const string OPTIONS = 'OPTIONS';
    public const string CONNECT = 'CONNECT';
    public const string TRACE = 'TRACE';

    private function __construct() {}
}
