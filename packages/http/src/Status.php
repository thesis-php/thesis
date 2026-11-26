<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * The names of the registered status codes in common use. A status is an int: any other number
 * from 100 to 999 is valid too.
 *
 * @api
 */
final class Status
{
    public const int CONTINUE = 100;
    public const int SWITCHING_PROTOCOLS = 101;
    public const int EARLY_HINTS = 103;
    public const int OK = 200;
    public const int CREATED = 201;
    public const int ACCEPTED = 202;
    public const int NO_CONTENT = 204;
    public const int RESET_CONTENT = 205;
    public const int PARTIAL_CONTENT = 206;
    public const int MULTIPLE_CHOICES = 300;
    public const int MOVED_PERMANENTLY = 301;
    public const int FOUND = 302;
    public const int SEE_OTHER = 303;
    public const int NOT_MODIFIED = 304;
    public const int TEMPORARY_REDIRECT = 307;
    public const int PERMANENT_REDIRECT = 308;
    public const int BAD_REQUEST = 400;
    public const int UNAUTHORIZED = 401;
    public const int FORBIDDEN = 403;
    public const int NOT_FOUND = 404;
    public const int METHOD_NOT_ALLOWED = 405;
    public const int NOT_ACCEPTABLE = 406;
    public const int REQUEST_TIMEOUT = 408;
    public const int CONFLICT = 409;
    public const int GONE = 410;
    public const int LENGTH_REQUIRED = 411;
    public const int PRECONDITION_FAILED = 412;
    public const int CONTENT_TOO_LARGE = 413;
    public const int URI_TOO_LONG = 414;
    public const int UNSUPPORTED_MEDIA_TYPE = 415;
    public const int RANGE_NOT_SATISFIABLE = 416;
    public const int EXPECTATION_FAILED = 417;
    public const int MISDIRECTED_REQUEST = 421;
    public const int UNPROCESSABLE_CONTENT = 422;
    public const int TOO_EARLY = 425;
    public const int UPGRADE_REQUIRED = 426;
    public const int PRECONDITION_REQUIRED = 428;
    public const int TOO_MANY_REQUESTS = 429;
    public const int REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
    public const int UNAVAILABLE_FOR_LEGAL_REASONS = 451;
    public const int INTERNAL_SERVER_ERROR = 500;
    public const int NOT_IMPLEMENTED = 501;
    public const int BAD_GATEWAY = 502;
    public const int SERVICE_UNAVAILABLE = 503;
    public const int GATEWAY_TIMEOUT = 504;
    public const int HTTP_VERSION_NOT_SUPPORTED = 505;

    /**
     * @return non-empty-string|null the standard phrase, or null for a code without a named constant
     */
    public static function reasonPhrase(int $code): ?string
    {
        return match ($code) {
            self::CONTINUE => 'Continue',
            self::SWITCHING_PROTOCOLS => 'Switching Protocols',
            self::EARLY_HINTS => 'Early Hints',
            self::OK => 'OK',
            self::CREATED => 'Created',
            self::ACCEPTED => 'Accepted',
            self::NO_CONTENT => 'No Content',
            self::RESET_CONTENT => 'Reset Content',
            self::PARTIAL_CONTENT => 'Partial Content',
            self::MULTIPLE_CHOICES => 'Multiple Choices',
            self::MOVED_PERMANENTLY => 'Moved Permanently',
            self::FOUND => 'Found',
            self::SEE_OTHER => 'See Other',
            self::NOT_MODIFIED => 'Not Modified',
            self::TEMPORARY_REDIRECT => 'Temporary Redirect',
            self::PERMANENT_REDIRECT => 'Permanent Redirect',
            self::BAD_REQUEST => 'Bad Request',
            self::UNAUTHORIZED => 'Unauthorized',
            self::FORBIDDEN => 'Forbidden',
            self::NOT_FOUND => 'Not Found',
            self::METHOD_NOT_ALLOWED => 'Method Not Allowed',
            self::NOT_ACCEPTABLE => 'Not Acceptable',
            self::REQUEST_TIMEOUT => 'Request Timeout',
            self::CONFLICT => 'Conflict',
            self::GONE => 'Gone',
            self::LENGTH_REQUIRED => 'Length Required',
            self::PRECONDITION_FAILED => 'Precondition Failed',
            self::CONTENT_TOO_LARGE => 'Content Too Large',
            self::URI_TOO_LONG => 'URI Too Long',
            self::UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type',
            self::RANGE_NOT_SATISFIABLE => 'Range Not Satisfiable',
            self::EXPECTATION_FAILED => 'Expectation Failed',
            self::MISDIRECTED_REQUEST => 'Misdirected Request',
            self::UNPROCESSABLE_CONTENT => 'Unprocessable Content',
            self::TOO_EARLY => 'Too Early',
            self::UPGRADE_REQUIRED => 'Upgrade Required',
            self::PRECONDITION_REQUIRED => 'Precondition Required',
            self::TOO_MANY_REQUESTS => 'Too Many Requests',
            self::REQUEST_HEADER_FIELDS_TOO_LARGE => 'Request Header Fields Too Large',
            self::UNAVAILABLE_FOR_LEGAL_REASONS => 'Unavailable For Legal Reasons',
            self::INTERNAL_SERVER_ERROR => 'Internal Server Error',
            self::NOT_IMPLEMENTED => 'Not Implemented',
            self::BAD_GATEWAY => 'Bad Gateway',
            self::SERVICE_UNAVAILABLE => 'Service Unavailable',
            self::GATEWAY_TIMEOUT => 'Gateway Timeout',
            self::HTTP_VERSION_NOT_SUPPORTED => 'HTTP Version Not Supported',
            default => null,
        };
    }

    private function __construct() {}
}
